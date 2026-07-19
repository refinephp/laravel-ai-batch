<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Data;

use RefinePhp\LaravelAiBatch\Exceptions\InvalidBatchRequestException;

final readonly class BatchResult
{
    /** @param array<string, mixed>|null $response */
    public function __construct(
        private string $customId,
        private ?string $providerRequestId,
        private ?int $statusCode,
        private ?array $response,
        private ?BatchError $error,
    ) {
        if ($customId === '') {
            throw new InvalidBatchRequestException('A batch result custom ID must not be empty.');
        }

        if (($response === null) === ($error === null)) {
            throw new InvalidBatchRequestException('A batch result must contain exactly one response or error.');
        }
    }

    public function customId(): string
    {
        return $this->customId;
    }

    public function providerRequestId(): ?string
    {
        return $this->providerRequestId;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function successful(): bool
    {
        return $this->error === null;
    }

    /** @return array<string, mixed>|null */
    public function response(): ?array
    {
        return $this->response;
    }

    public function error(): ?BatchError
    {
        return $this->error;
    }
}
