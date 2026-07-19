<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use RefinePhp\LaravelAiBatch\BatchManager;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;
use RefinePhp\LaravelAiBatch\Jobs\PollBatch;
use RefinePhp\LaravelAiBatch\Polling\BatchCanceller;

final class CommandBatchRepository implements BatchRepository
{
    /** @param array<string, ProviderBatch> $batches */
    public function __construct(public array $batches = []) {}

    public function save(ProviderBatch $batch): void
    {
        $this->batches[$batch->id()] = $batch;
    }

    public function find(string $id): ?ProviderBatch
    {
        return $this->batches[$id] ?? null;
    }

    public function pollable(int $limit = 100): iterable
    {
        $pollable = array_filter(
            $this->batches,
            fn (ProviderBatch $batch): bool => ! $batch->isTerminal(),
        );

        return array_slice(array_values($pollable), 0, $limit);
    }
}

function commandSnapshot(string $id, BatchStatus $status = BatchStatus::InProgress): ProviderBatch
{
    return new ProviderBatch(
        id: $id,
        provider: 'openai',
        providerBatchId: "remote-{$id}",
        name: "Example\nname",
        status: $status,
        providerStatus: $status->value,
        inputFileId: 'file-input',
        outputFileId: null,
        errorFileId: null,
        requestCount: 3,
        completedCount: 1,
        failedCount: 0,
    );
}

beforeEach(function (): void {
    $this->app->forgetInstance(BatchManager::class);
    $this->app->forgetInstance(BatchCanceller::class);
});

it('dispatches one queued job per pollable batch and respects the limit', function (): void {
    Bus::fake();
    $repository = new CommandBatchRepository([
        'batch-a' => commandSnapshot('batch-a'),
        'batch-b' => commandSnapshot('batch-b'),
        'batch-done' => commandSnapshot('batch-done', BatchStatus::Completed),
    ]);
    $this->app->instance(BatchRepository::class, $repository);

    $this->artisan('ai:batch:poll', ['--limit' => 1])
        ->expectsOutputToContain('Dispatched 1 AI batch poll job.')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(PollBatch::class, 1);
    Bus::assertDispatched(PollBatch::class, fn (PollBatch $job): bool => $job->batch === 'batch-a');
});

it('rejects an invalid polling limit without dispatching work', function (): void {
    Bus::fake();
    $this->app->instance(BatchRepository::class, new CommandBatchRepository);

    $this->artisan('ai:batch:poll', ['--limit' => 'unbounded'])
        ->expectsOutputToContain('The --limit option must be an integer between 1 and 10000.')
        ->assertExitCode(2);

    Bus::assertNothingDispatched();
});

it('shows locally persisted status with control characters sanitized', function (): void {
    $this->app->instance(BatchRepository::class, new CommandBatchRepository([
        'batch-status' => commandSnapshot('batch-status'),
    ]));

    $this->artisan('ai:batch:status', ['batch' => 'batch-status'])
        ->expectsOutputToContain('batch-status')
        ->expectsOutputToContain('Example?name')
        ->expectsOutputToContain('1 completed, 0 failed, 3 total')
        ->assertSuccessful();
});

it('reports missing status and cancellation targets without unsafe identifiers', function (): void {
    $this->app->instance(BatchRepository::class, new CommandBatchRepository);

    $this->artisan('ai:batch:status', ['batch' => "missing\nbatch"])
        ->expectsOutputToContain('AI batch [missing?batch] was not found.')
        ->assertFailed();

    $this->artisan('ai:batch:cancel', ['batch' => "missing\nbatch"])
        ->expectsOutputToContain('AI batch [missing?batch] was not found.')
        ->assertFailed();
});
