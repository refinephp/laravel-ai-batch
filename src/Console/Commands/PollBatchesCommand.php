<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Console\Commands;

use Illuminate\Contracts\Bus\Dispatcher;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Jobs\PollBatch;

final class PollBatchesCommand extends BatchCommand
{
    protected $signature = 'ai:batch:poll
                            {--limit=100 : Maximum number of poll jobs to dispatch}';

    protected $description = 'Dispatch polling jobs for non-terminal AI batches';

    public function handle(BatchRepository $repository, Dispatcher $dispatcher): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 10_000],
        ]);

        if (! is_int($limit)) {
            $this->components->error('The --limit option must be an integer between 1 and 10000.');

            return self::INVALID;
        }

        $count = 0;

        foreach ($repository->pollable($limit) as $batch) {
            $dispatcher->dispatch(new PollBatch($batch->id()));
            $count++;
        }

        $this->components->info("Dispatched {$count} AI batch poll ".($count === 1 ? 'job.' : 'jobs.'));

        return self::SUCCESS;
    }
}
