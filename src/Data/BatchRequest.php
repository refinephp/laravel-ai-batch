<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Data;

use RefinePhp\LaravelAiBatch\Exceptions\InvalidCustomIdException;

final readonly class BatchRequest
{
    public function __construct(
        private string $customId,
        private ResolvedProviderRequest $request,
    ) {
        if ($customId === '') {
            throw new InvalidCustomIdException('A batch request custom ID must not be empty.');
        }
    }

    public function customId(): string
    {
        return $this->customId;
    }

    public function request(): ResolvedProviderRequest
    {
        return $this->request;
    }
}
