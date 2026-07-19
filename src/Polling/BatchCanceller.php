<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Polling;

use RefinePhp\LaravelAiBatch\BatchManager;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Exceptions\BatchNotFoundException;

final class BatchCanceller
{
    public function __construct(
        private readonly BatchRepository $repository,
        private readonly BatchManager $manager,
        private readonly BatchLock $lock,
    ) {}

    public function cancel(string $batchId): ?ProviderBatch
    {
        return $this->lock->run($batchId, function () use ($batchId): ProviderBatch {
            $batch = $this->repository->find($batchId)
                ?? throw new BatchNotFoundException('The requested batch was not found.');

            if (! $batch->canBeCancelled()) {
                return $batch;
            }

            return $this->manager->cancel($batch);
        });
    }
}
