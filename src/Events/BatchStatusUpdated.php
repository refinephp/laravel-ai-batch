<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Events;

use RefinePhp\LaravelAiBatch\Models\BatchRecord;

final class BatchStatusUpdated
{
    public function __construct(
        public readonly BatchRecord $batch
    ) {}
}
