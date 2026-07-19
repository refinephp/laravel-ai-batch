<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Contracts;

use RefinePhp\LaravelAiBatch\Data\ProviderBatch;

interface BatchRepository
{
    public function save(ProviderBatch $batch): void;

    public function find(string $id): ?ProviderBatch;

    /** @return iterable<int, ProviderBatch> */
    public function pollable(int $limit = 100): iterable;
}
