<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Data;

use RefinePhp\LaravelAiBatch\Exceptions\DuplicateCustomIdException;
use RefinePhp\LaravelAiBatch\Exceptions\EmptyBatchException;
use RefinePhp\LaravelAiBatch\Exceptions\InvalidBatchRequestException;

final readonly class BatchSubmission
{
    /** @var list<BatchRequest> */
    private array $requests;

    /** @param array<int, mixed> $requests */
    public function __construct(
        private string $id,
        private string $provider,
        private ?string $name,
        private string $completionWindow,
        array $requests,
    ) {
        if ($id === '' || $provider === '' || $completionWindow === '') {
            throw new InvalidBatchRequestException('Batch submission identifiers and completion window must not be empty.');
        }

        if ($requests === []) {
            throw new EmptyBatchException('A batch submission must contain at least one request.');
        }

        $seen = [];

        foreach ($requests as $request) {
            if (! $request instanceof BatchRequest) {
                throw new InvalidBatchRequestException('Every batch submission item must be a BatchRequest.');
            }

            if (isset($seen[$request->customId()])) {
                throw new DuplicateCustomIdException("Duplicate batch custom ID [{$request->customId()}].");
            }

            $seen[$request->customId()] = true;
        }

        $this->requests = array_values($requests);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function completionWindow(): string
    {
        return $this->completionWindow;
    }

    /** @return list<BatchRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
