<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Providers\OpenAI;

use RefinePhp\LaravelAiBatch\Exceptions\BatchException;
use Throwable;

final class OpenAiJsonlException extends BatchException
{
    public function __construct(
        string $message,
        private readonly ?string $fileId = null,
        private readonly ?int $lineNumber = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function fileId(): ?string
    {
        return $this->fileId;
    }

    public function line(): ?int
    {
        return $this->lineNumber;
    }
}
