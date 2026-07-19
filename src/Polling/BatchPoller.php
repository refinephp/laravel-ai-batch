<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Polling;

use RefinePhp\LaravelAiBatch\BatchManager;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Exceptions\BatchNotFoundException;

final class BatchPoller
{
    public function __construct(
        private readonly BatchRepository $repository,
        private readonly BatchManager $manager,
        private readonly BatchLock $lock,
    ) {}

    public function poll(string $batchId): ?ProviderBatch
    {
        return $this->lock->run($batchId, function () use ($batchId): ProviderBatch {
            $batch = $this->repository->find($batchId)
                ?? throw new BatchNotFoundException('The requested batch was not found.');

            if ($batch->isTerminal()) {
                return $batch;
            }

            return $this->manager->refresh($batch);
        });
    }
}
