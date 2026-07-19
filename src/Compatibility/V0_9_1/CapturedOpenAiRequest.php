<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Compatibility\V0_9_1;

use RuntimeException;

/** @internal */
final class CapturedOpenAiRequest extends RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(private readonly array $body)
    {
        parent::__construct('The initial OpenAI request was captured before transport.');
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }
}
