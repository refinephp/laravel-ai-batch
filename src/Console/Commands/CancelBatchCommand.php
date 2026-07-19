<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Console\Commands;

use RefinePhp\LaravelAiBatch\Exceptions\BatchNotFoundException;
use RefinePhp\LaravelAiBatch\Polling\BatchCanceller;

final class CancelBatchCommand extends BatchCommand
{
    protected $signature = 'ai:batch:cancel {batch : Local batch ID}';

    protected $description = 'Request cancellation of an AI batch';

    public function handle(BatchCanceller $canceller): int
    {
        $id = $this->argument('batch');

        if (! is_string($id) || $id === '') {
            $this->components->error('The batch argument must be a non-empty local batch ID.');

            return self::INVALID;
        }

        try {
            $batch = $canceller->cancel($id);
        } catch (BatchNotFoundException) {
            $this->components->error("AI batch [{$this->safe($id)}] was not found.");

            return self::FAILURE;
        }

        if ($batch === null) {
            $this->components->warn('The batch is already being updated; no cancellation request was sent.');

            return self::SUCCESS;
        }

        $this->components->info("AI batch status: {$batch->status()->value}.");

        return self::SUCCESS;
    }
}
