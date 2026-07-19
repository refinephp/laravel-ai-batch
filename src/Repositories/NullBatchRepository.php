<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Repositories;

use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;

final class NullBatchRepository implements BatchRepository
{
    public function save(ProviderBatch $batch): void {}

    public function find(string $id): ?ProviderBatch
    {
        return null;
    }

    public function pollable(int $limit = 100): iterable
    {
        return [];
    }
}
