<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Data;

final readonly class BatchError
{
    public function __construct(
        private ?string $customId,
        private ?string $code,
        private string $message,
        private ?string $parameter = null,
        private ?int $line = null,
        private ?int $statusCode = null,
        private ?string $providerRequestId = null,
    ) {}

    public function customId(): ?string
    {
        return $this->customId;
    }

    public function code(): ?string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function parameter(): ?string
    {
        return $this->parameter;
    }

    public function line(): ?int
    {
        return $this->line;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function providerRequestId(): ?string
    {
        return $this->providerRequestId;
    }
}
