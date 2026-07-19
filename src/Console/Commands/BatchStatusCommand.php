<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Console\Commands;

use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;

final class BatchStatusCommand extends BatchCommand
{
    protected $signature = 'ai:batch:status {batch : Local batch ID}';

    protected $description = 'Display the locally persisted status of an AI batch';

    public function handle(BatchRepository $repository): int
    {
        $id = $this->argument('batch');

        if (! is_string($id) || $id === '') {
            $this->components->error('The batch argument must be a non-empty local batch ID.');

            return self::INVALID;
        }

        $batch = $repository->find($id);

        if ($batch === null) {
            $this->components->error("AI batch [{$this->safe($id)}] was not found.");

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['Local ID', $this->safe($batch->id())],
            ['Provider', $this->safe($batch->provider())],
            ['Provider batch ID', $this->safe($batch->providerBatchId())],
            ['Name', $this->safe($batch->name() ?? '-')],
            ['Status', $batch->status()->value],
            ['Provider status', $this->safe($batch->providerStatus())],
            ['Progress', "{$batch->completedCount()} completed, {$batch->failedCount()} failed, {$batch->requestCount()} total"],
            ['Output file ID', $this->safe($batch->outputFileId() ?? '-')],
            ['Error file ID', $this->safe($batch->errorFileId() ?? '-')],
        ]);

        return self::SUCCESS;
    }
}
