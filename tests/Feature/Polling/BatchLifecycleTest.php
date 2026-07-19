<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use RefinePhp\LaravelAiBatch\BatchManager;
use RefinePhp\LaravelAiBatch\Contracts\BatchProvider;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Contracts\RequestResolver;
use RefinePhp\LaravelAiBatch\Data\BatchError;
use RefinePhp\LaravelAiBatch\Data\BatchResult;
use RefinePhp\LaravelAiBatch\Data\BatchSubmission;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;
use RefinePhp\LaravelAiBatch\Jobs\PollBatch;
use RefinePhp\LaravelAiBatch\Polling\BatchCanceller;
use RefinePhp\LaravelAiBatch\Polling\BatchLock;
use RefinePhp\LaravelAiBatch\Polling\BatchPoller;

final class PollingInMemoryRepository implements BatchRepository
{
    /** @var array<string, ProviderBatch> */
    public array $batches = [];

    public int $finds = 0;

    public int $saves = 0;

    public function save(ProviderBatch $batch): void
    {
        $this->saves++;
        $this->batches[$batch->id()] = $batch;
    }

    public function find(string $id): ?ProviderBatch
    {
        $this->finds++;

        return $this->batches[$id] ?? null;
    }

    public function pollable(int $limit = 100): iterable
    {
        return array_slice(array_values($this->batches), 0, $limit);
    }
}

final class PollingFakeProvider implements BatchProvider
{
    public int $refreshes = 0;

    public int $cancellations = 0;

    public function name(): string
    {
        return 'openai';
    }

    public function submit(BatchSubmission $submission): ProviderBatch
    {
        throw new LogicException('Not used by polling tests.');
    }

    public function refresh(ProviderBatch $batch): ProviderBatch
    {
        $this->refreshes++;

        return pollingSnapshot($batch->id(), BatchStatus::Completed, completed: 2);
    }

    public function cancel(ProviderBatch $batch): ProviderBatch
    {
        $this->cancellations++;

        return pollingSnapshot($batch->id(), BatchStatus::Cancelling);
    }

    /** @return iterable<int, BatchResult> */
    public function results(ProviderBatch $batch): iterable
    {
        return [];
    }

    /** @return iterable<int, BatchError> */
    public function errors(ProviderBatch $batch): iterable
    {
        return [];
    }
}

function pollingSnapshot(
    string $id,
    BatchStatus $status,
    int $completed = 0,
    int $failed = 0,
): ProviderBatch {
    return new ProviderBatch(
        id: $id,
        provider: 'openai',
        providerBatchId: "remote-{$id}",
        name: 'Polling test',
        status: $status,
        providerStatus: $status->value,
        inputFileId: 'file-input',
        outputFileId: $status === BatchStatus::Completed ? 'file-output' : null,
        errorFileId: null,
        requestCount: 2,
        completedCount: $completed,
        failedCount: $failed,
        submittedAt: CarbonImmutable::parse('2026-07-19 12:00:00'),
    );
}

/** @return array{PollingInMemoryRepository, PollingFakeProvider, BatchLock, BatchManager} */
function pollingDependencies(): array
{
    $repository = new PollingInMemoryRepository;
    $provider = new PollingFakeProvider;
    $container = app(Container::class);
    $container->instance(PollingFakeProvider::class, $provider);

    $manager = new BatchManager(
        container: $container,
        resolver: Mockery::mock(RequestResolver::class),
        repository: $repository,
        providers: ['openai' => PollingFakeProvider::class],
    );

    $lock = new BatchLock(app(CacheFactory::class), seconds: 60);

    return [$repository, $provider, $lock, $manager];
}

it('polls once under a lock and skips the terminal snapshot on duplicate execution', function (): void {
    [$repository, $provider, $lock, $manager] = pollingDependencies();
    $repository->batches['batch-poll'] = pollingSnapshot('batch-poll', BatchStatus::InProgress);
    $poller = new BatchPoller($repository, $manager, $lock);

    $first = $poller->poll('batch-poll');
    $second = $poller->poll('batch-poll');

    expect($first?->status())->toBe(BatchStatus::Completed)
        ->and($second?->status())->toBe(BatchStatus::Completed)
        ->and($provider->refreshes)->toBe(1)
        ->and($repository->saves)->toBe(1)
        ->and($repository->finds)->toBe(2);
});

it('does nothing when another lifecycle operation owns the batch lock', function (): void {
    [$repository, $provider, $batchLock, $manager] = pollingDependencies();
    $repository->batches['batch-locked'] = pollingSnapshot('batch-locked', BatchStatus::InProgress);
    $store = app(CacheFactory::class)->store()->getStore();

    expect($store)->toBeInstanceOf(LockProvider::class);

    /** @var LockProvider $store */
    $lock = $store->lock('ai-batch:lifecycle:'.hash('sha256', 'batch-locked'), 60);
    expect($lock->get())->toBeTrue();

    try {
        $result = (new BatchPoller($repository, $manager, $batchLock))->poll('batch-locked');
    } finally {
        $lock->release();
    }

    expect($result)->toBeNull()
        ->and($provider->refreshes)->toBe(0)
        ->and($repository->finds)->toBe(0)
        ->and($repository->saves)->toBe(0);
});

it('makes cancellation idempotent through the same lifecycle lock', function (): void {
    [$repository, $provider, $lock, $manager] = pollingDependencies();
    $repository->batches['batch-cancel'] = pollingSnapshot('batch-cancel', BatchStatus::InProgress);
    $canceller = new BatchCanceller($repository, $manager, $lock);

    $first = $canceller->cancel('batch-cancel');
    $second = $canceller->cancel('batch-cancel');

    expect($first?->status())->toBe(BatchStatus::Cancelling)
        ->and($second?->status())->toBe(BatchStatus::Cancelling)
        ->and($provider->cancellations)->toBe(1)
        ->and($repository->saves)->toBe(1)
        ->and($repository->finds)->toBe(2);
});

it('provides a queued job that delegates to the idempotent poller', function (): void {
    [$repository, $provider, $lock, $manager] = pollingDependencies();
    $repository->batches['batch-job'] = pollingSnapshot('batch-job', BatchStatus::InProgress);
    $job = new PollBatch('batch-job');

    $job->handle(new BatchPoller($repository, $manager, $lock));

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($provider->refreshes)->toBe(1)
        ->and($repository->batches['batch-job']->status())->toBe(BatchStatus::Completed);
});
