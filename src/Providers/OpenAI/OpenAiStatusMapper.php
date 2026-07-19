<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Providers\OpenAI;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RefinePhp\LaravelAiBatch\Data\BatchError;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;

final class OpenAiStatusMapper
{
    /** @param array<string, mixed> $payload */
    public function map(
        array $payload,
        string $localId,
        string $provider,
        ?string $name,
        ?string $inputFileId = null,
        ?ProviderBatch $previous = null,
        int $requestCount = 0,
    ): ProviderBatch {
        $providerBatchId = $this->requiredString($payload, 'id');
        $providerStatus = $this->requiredString($payload, 'status');
        $status = BatchStatus::tryFrom($providerStatus) ?? BatchStatus::Unknown;

        if ($previous !== null && $providerBatchId !== $previous->providerBatchId()) {
            throw new OpenAiPayloadException('The OpenAI batch response contained an unexpected batch ID.');
        }

        if ($previous !== null && ! $this->canTransition($previous->status(), $status)) {
            throw new OpenAiPayloadException('The OpenAI batch response attempted an invalid lifecycle status transition.');
        }

        $counts = $payload['request_counts'] ?? [];
        $counts = is_array($counts) && ! array_is_list($counts) ? $counts : [];
        $requestCount = $this->count($counts, 'total', $previous?->requestCount() ?? $requestCount);
        $completedCount = $this->count($counts, 'completed', $previous?->completedCount() ?? 0);
        $failedCount = $this->count($counts, 'failed', $previous?->failedCount() ?? 0);
        $requestCount = max($requestCount, $completedCount + $failedCount);

        return new ProviderBatch(
            id: $localId,
            provider: $provider,
            providerBatchId: $providerBatchId,
            name: $name,
            status: $status,
            providerStatus: $providerStatus,
            inputFileId: $this->nullableString($payload['input_file_id'] ?? null)
                ?? $inputFileId
                ?? $previous?->inputFileId(),
            outputFileId: $this->nullableString($payload['output_file_id'] ?? null) ?? $previous?->outputFileId(),
            errorFileId: $this->nullableString($payload['error_file_id'] ?? null) ?? $previous?->errorFileId(),
            requestCount: $requestCount,
            completedCount: $completedCount,
            failedCount: $failedCount,
            validationErrors: $this->validationErrors($payload['errors'] ?? null, $previous?->validationErrors() ?? []),
            submittedAt: $this->timestamp($payload['created_at'] ?? null) ?? $previous?->submittedAt(),
            completedAt: $this->timestamp($payload['completed_at'] ?? null) ?? $previous?->completedAt(),
            failedAt: $this->timestamp($payload['failed_at'] ?? null) ?? $previous?->failedAt(),
            expiresAt: $this->timestamp($payload['expires_at'] ?? null) ?? $previous?->expiresAt(),
        );
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new OpenAiPayloadException("The OpenAI batch response is missing a valid {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $counts */
    private function count(array $counts, string $key, int $fallback): int
    {
        $value = $counts[$key] ?? null;

        return is_int($value) && $value >= 0 ? max($value, $fallback) : $fallback;
    }

    /**
     * @param  list<BatchError>  $fallback
     * @return list<BatchError>
     */
    private function validationErrors(mixed $errors, array $fallback): array
    {
        if ($errors === null) {
            return $fallback;
        }

        if (! is_array($errors) || array_is_list($errors)) {
            return [];
        }

        $data = $errors['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        $mapped = [];

        foreach ($data as $error) {
            if (! is_array($error) || array_is_list($error)) {
                continue;
            }

            $mapped[] = new BatchError(
                customId: null,
                code: $this->nullableString($error['code'] ?? null),
                message: $this->safeMessage($error['message'] ?? null) ?? 'OpenAI rejected the batch input.',
                parameter: $this->nullableString($error['param'] ?? null),
                line: is_int($error['line'] ?? null) && $error['line'] >= 0 ? $error['line'] : null,
            );
        }

        return $mapped;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_int($value) || $value < 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($value);
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

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function safeMessage(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Str::limit(preg_replace('/[\r\n\t]+/u', ' ', $value) ?? 'Provider error.', 500, '...');
    }
}
