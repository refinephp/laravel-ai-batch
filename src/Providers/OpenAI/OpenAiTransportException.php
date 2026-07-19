<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Providers\OpenAI;

use RefinePhp\LaravelAiBatch\Exceptions\BatchException;
use Throwable;

final class OpenAiTransportException extends BatchException
{
    public function __construct(
        string $message,
        private readonly string $operation,
        private readonly ?int $statusCode = null,
        private readonly ?string $providerCode = null,
        private readonly ?string $requestId = null,
        private readonly ?string $clientRequestId = null,
        private readonly ?string $resourceId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function providerCode(): ?string
    {
        return $this->providerCode;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function clientRequestId(): ?string
    {
        return $this->clientRequestId;
    }

    public function resourceId(): ?string
    {
        return $this->resourceId;
    }
}
