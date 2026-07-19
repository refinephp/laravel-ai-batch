<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Data;

use Carbon\CarbonImmutable;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;

final readonly class ProviderBatch
{
    /** @var list<BatchError> */
    private array $validationErrors;

    /** @param array<int, BatchError> $validationErrors */
    public function __construct(
        private string $id,
        private string $provider,
        private string $providerBatchId,
        private ?string $name,
        private BatchStatus $status,
        private string $providerStatus,
        private ?string $inputFileId,
        private ?string $outputFileId,
        private ?string $errorFileId,
        private int $requestCount,
        private int $completedCount,
        private int $failedCount,
        array $validationErrors = [],
        private ?CarbonImmutable $submittedAt = null,
        private ?CarbonImmutable $completedAt = null,
        private ?CarbonImmutable $failedAt = null,
        private ?CarbonImmutable $expiresAt = null,
    ) {
        $this->validationErrors = array_values($validationErrors);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function providerBatchId(): string
    {
        return $this->providerBatchId;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function status(): BatchStatus
    {
        return $this->status;
    }

    public function providerStatus(): string
    {
        return $this->providerStatus;
    }

    public function inputFileId(): ?string
    {
        return $this->inputFileId;
    }

    public function outputFileId(): ?string
    {
        return $this->outputFileId;
    }

    public function errorFileId(): ?string
    {
        return $this->errorFileId;
    }

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    public function completedCount(): int
    {
        return $this->completedCount;
    }

    public function failedCount(): int
    {
        return $this->failedCount;
    }

    /** @return list<BatchError> */
    public function validationErrors(): array
    {
        return $this->validationErrors;
    }

    public function submittedAt(): ?CarbonImmutable
    {
        return $this->submittedAt;
    }

    public function completedAt(): ?CarbonImmutable
    {
        return $this->completedAt;
    }

    public function failedAt(): ?CarbonImmutable
    {
        return $this->failedAt;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiresAt;
    }

    public function isCompleted(): bool
    {
        return $this->status === BatchStatus::Completed;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function canBeCancelled(): bool
    {
        return $this->status->canBeCancelled();
    }
}
