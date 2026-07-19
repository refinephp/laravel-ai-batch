<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Repositories;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Data\BatchError;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;
use RefinePhp\LaravelAiBatch\Exceptions\BatchPersistenceException;
use RefinePhp\LaravelAiBatch\Models\BatchRecord;
use Throwable;

final class EloquentBatchRepository implements BatchRepository
{
    public function save(ProviderBatch $batch): void
    {
        try {
            DB::transaction(function () use ($batch): void {
                $existing = BatchRecord::query()->lockForUpdate()->find($batch->id());
                $current = $existing instanceof BatchRecord ? $this->hydrate($existing) : null;

                if ($current !== null) {
                    $batch = $this->merge($current, $batch);
                }

                BatchRecord::query()->updateOrCreate(
                    ['id' => $batch->id()],
                    [
                        'provider' => $batch->provider(),
                        'provider_batch_id' => $batch->providerBatchId(),
                        'name' => $batch->name(),
                        'status' => $batch->status(),
                        'provider_status' => $batch->providerStatus(),
                        'input_file_id' => $batch->inputFileId(),
                        'output_file_id' => $batch->outputFileId(),
                        'error_file_id' => $batch->errorFileId(),
                        'request_count' => $batch->requestCount(),
                        'completed_count' => $batch->completedCount(),
                        'failed_count' => $batch->failedCount(),
                        'validation_errors' => array_map(
                            fn (BatchError $error): array => [
                                'custom_id' => $error->customId(),
                                'code' => $error->code(),
                                'message' => $error->message(),
                                'parameter' => $error->parameter(),
                                'line' => $error->line(),
                                'status_code' => $error->statusCode(),
                                'provider_request_id' => $error->providerRequestId(),
                            ],
                            $batch->validationErrors(),
                        ),
                        'submitted_at' => $batch->submittedAt(),
                        'completed_at' => $batch->completedAt(),
                        'failed_at' => $batch->failedAt(),
                        'expires_at' => $batch->expiresAt(),
                    ],
                );
            });
        } catch (BatchPersistenceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BatchPersistenceException(
                "Unable to persist batch [{$batch->id()}].",
                previous: $exception,
            );
        }
    }

    private function merge(ProviderBatch $current, ProviderBatch $incoming): ProviderBatch
    {
        if ($current->provider() !== $incoming->provider()) {
            throw new BatchPersistenceException(
                "Batch [{$incoming->id()}] cannot change its provider connection.",
            );
        }

        if ($current->providerBatchId() !== $incoming->providerBatchId()) {
            throw new BatchPersistenceException(
                "Batch [{$incoming->id()}] cannot change its provider batch ID.",
            );
        }

        $advances = $this->canTransition($current->status(), $incoming->status());
        $status = $advances ? $incoming->status() : $current->status();
        $providerStatus = $advances ? $incoming->providerStatus() : $current->providerStatus();
        $requestCount = max($current->requestCount(), $incoming->requestCount());
        $completedCount = max($current->completedCount(), $incoming->completedCount());
        $failedCount = max($current->failedCount(), $incoming->failedCount());

        return new ProviderBatch(
            id: $current->id(),
            provider: $current->provider(),
            providerBatchId: $current->providerBatchId(),
            name: $incoming->name() ?? $current->name(),
            status: $status,
            providerStatus: $providerStatus,
            inputFileId: $incoming->inputFileId() ?? $current->inputFileId(),
            outputFileId: $incoming->outputFileId() ?? $current->outputFileId(),
            errorFileId: $incoming->errorFileId() ?? $current->errorFileId(),
            requestCount: max($requestCount, $completedCount + $failedCount),
            completedCount: $completedCount,
            failedCount: $failedCount,
            validationErrors: $incoming->validationErrors() !== []
                ? $incoming->validationErrors()
                : $current->validationErrors(),
            submittedAt: $incoming->submittedAt() ?? $current->submittedAt(),
            completedAt: $incoming->completedAt() ?? $current->completedAt(),
            failedAt: $incoming->failedAt() ?? $current->failedAt(),
            expiresAt: $incoming->expiresAt() ?? $current->expiresAt(),
        );
    }

    private function canTransition(BatchStatus $from, BatchStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        if ($from->isTerminal()) {
            return false;
        }

        if ($from === BatchStatus::Unknown || $to === BatchStatus::Unknown) {
            return true;
        }

        return match ($from) {
            BatchStatus::Validating => in_array($to, [
                BatchStatus::InProgress,
                BatchStatus::Finalizing,
                BatchStatus::Completed,
                BatchStatus::Failed,
                BatchStatus::Expired,
                BatchStatus::Cancelling,
                BatchStatus::Cancelled,
            ], true),
            BatchStatus::InProgress => in_array($to, [
                BatchStatus::Finalizing,
                BatchStatus::Completed,
                BatchStatus::Failed,
                BatchStatus::Expired,
                BatchStatus::Cancelling,
                BatchStatus::Cancelled,
            ], true),
            BatchStatus::Finalizing => in_array($to, [
                BatchStatus::Completed,
                BatchStatus::Failed,
                BatchStatus::Expired,
                BatchStatus::Cancelling,
                BatchStatus::Cancelled,
            ], true),
            BatchStatus::Cancelling => in_array($to, [
                BatchStatus::Cancelled,
                BatchStatus::Completed,
                BatchStatus::Expired,
            ], true),
            default => false,
        };
    }

    public function find(string $id): ?ProviderBatch
    {
        try {
            $record = BatchRecord::query()->find($id);

            return $record instanceof BatchRecord ? $this->hydrate($record) : null;
        } catch (Throwable $exception) {
            throw new BatchPersistenceException("Unable to find batch [{$id}].", previous: $exception);
        }
    }

    public function pollable(int $limit = 100): iterable
    {
        try {
            $records = BatchRecord::query()
                ->whereNotIn('status', [
                    BatchStatus::Completed->value,
                    BatchStatus::Failed->value,
                    BatchStatus::Expired->value,
                    BatchStatus::Cancelled->value,
                ])
                ->orderBy('submitted_at')
                ->orderBy('id')
                ->limit(max(0, $limit))
                ->get();

            foreach ($records as $record) {
                yield $this->hydrate($record);
            }
        } catch (Throwable $exception) {
            throw new BatchPersistenceException('Unable to retrieve pollable batches.', previous: $exception);
        }
    }

    private function hydrate(BatchRecord $record): ProviderBatch
    {
        $status = $record->getAttribute('status');

        return new ProviderBatch(
            id: (string) $record->getAttribute('id'),
            provider: (string) $record->getAttribute('provider'),
            providerBatchId: (string) $record->getAttribute('provider_batch_id'),
            name: $this->nullableString($record->getAttribute('name')),
            status: $status instanceof BatchStatus ? $status : BatchStatus::from((string) $status),
            providerStatus: (string) $record->getAttribute('provider_status'),
            inputFileId: $this->nullableString($record->getAttribute('input_file_id')),
            outputFileId: $this->nullableString($record->getAttribute('output_file_id')),
            errorFileId: $this->nullableString($record->getAttribute('error_file_id')),
            requestCount: (int) $record->getAttribute('request_count'),
            completedCount: (int) $record->getAttribute('completed_count'),
            failedCount: (int) $record->getAttribute('failed_count'),
            validationErrors: $this->validationErrors($record->getAttribute('validation_errors')),
            submittedAt: $this->immutableDate($record->getAttribute('submitted_at')),
            completedAt: $this->immutableDate($record->getAttribute('completed_at')),
            failedAt: $this->immutableDate($record->getAttribute('failed_at')),
            expiresAt: $this->immutableDate($record->getAttribute('expires_at')),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function immutableDate(mixed $value): ?CarbonImmutable
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : null;
    }

    /** @return list<BatchError> */
    private function validationErrors(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $errors[] = new BatchError(
                customId: $this->nullableString($item['custom_id'] ?? null),
                code: $this->nullableString($item['code'] ?? null),
                message: (string) ($item['message'] ?? 'Batch validation failed.'),
                parameter: $this->nullableString($item['parameter'] ?? null),
                line: is_int($item['line'] ?? null) ? $item['line'] : null,
                statusCode: is_int($item['status_code'] ?? null) ? $item['status_code'] : null,
                providerRequestId: $this->nullableString($item['provider_request_id'] ?? null),
            );
        }

        return $errors;
    }
}
