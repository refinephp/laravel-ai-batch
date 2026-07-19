<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use RefinePhp\LaravelAiBatch\Polling\BatchPoller;

final class PollBatch implements ShouldQueue
{
    public function __construct(public readonly string $batch) {}

    public function handle(BatchPoller $poller): void
    {
        $poller->poll($this->batch);
    }
}
