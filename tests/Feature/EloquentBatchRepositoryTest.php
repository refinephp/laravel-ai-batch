<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RefinePhp\LaravelAiBatch\Data\BatchError;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;
use RefinePhp\LaravelAiBatch\Exceptions\BatchPersistenceException;
use RefinePhp\LaravelAiBatch\Repositories\EloquentBatchRepository;

/** @param list<BatchError> $validationErrors */
function snapshot(
    string $id,
    BatchStatus $status,
    int $completed = 0,
    array $validationErrors = [],
    ?string $providerBatchId = null,
    ?string $outputFileId = null,
    ?CarbonImmutable $completedAt = null,
): ProviderBatch {
    return new ProviderBatch(
        id: $id,
        provider: 'openai',
        providerBatchId: $providerBatchId ?? "remote-{$id}",
        name: 'Example',
        status: $status,
        providerStatus: $status->value,
        inputFileId: 'input-file',
        outputFileId: $outputFileId,
        errorFileId: null,
        requestCount: 2,
        completedCount: $completed,
        failedCount: 0,
        validationErrors: $validationErrors,
        submittedAt: CarbonImmutable::parse('2026-07-19 12:00:00'),
        completedAt: $completedAt,
    );
}

it('idempotently saves and hydrates immutable snapshots', function (): void {
    $repository = new EloquentBatchRepository;
    $repository->save(snapshot('batch-a', BatchStatus::InProgress));
    $repository->save(snapshot('batch-a', BatchStatus::Completed, 2, [
        new BatchError(null, 'invalid_request', 'A request failed validation.', line: 4),
    ]));

    $batch = $repository->find('batch-a');

    expect($batch)->not->toBeNull()
        ->and($batch?->status())->toBe(BatchStatus::Completed)
        ->and($batch?->completedCount())->toBe(2)
        ->and($batch?->validationErrors()[0]->line())->toBe(4)
        ->and($batch?->submittedAt())->toBeInstanceOf(CarbonImmutable::class)
        ->and($repository->find('missing'))->toBeNull();
});

it('returns only pollable snapshots in deterministic order', function (): void {
    $repository = new EloquentBatchRepository;
    $repository->save(snapshot('batch-b', BatchStatus::Validating));
    $repository->save(snapshot('batch-a', BatchStatus::InProgress));
    $repository->save(snapshot('batch-c', BatchStatus::Completed, 2));

    $ids = array_map(
        fn (ProviderBatch $batch): string => $batch->id(),
        iterator_to_array($repository->pollable()),
    );

    expect($ids)->toBe(['batch-a', 'batch-b']);
});

it('never regresses a terminal snapshot to a different status', function (): void {
    $repository = new EloquentBatchRepository;
    $repository->save(snapshot('batch-terminal', BatchStatus::Completed, 2));
    $repository->save(snapshot('batch-terminal', BatchStatus::InProgress, 1));
    $repository->save(snapshot('batch-terminal', BatchStatus::Cancelled, 1));

    $batch = $repository->find('batch-terminal');

    expect($batch?->status())->toBe(BatchStatus::Completed)
        ->and($batch?->completedCount())->toBe(2);
});

it('merges stale nonterminal snapshots without losing monotonic data', function (): void {
    $repository = new EloquentBatchRepository;
    $completedAt = CarbonImmutable::parse('2026-07-19 13:00:00');

    $repository->save(snapshot(
        id: 'batch-race',
        status: BatchStatus::Finalizing,
        completed: 1,
        outputFileId: 'file-output',
        completedAt: $completedAt,
    ));
    $repository->save(snapshot('batch-race', BatchStatus::InProgress));

    $batch = $repository->find('batch-race');

    expect($batch?->status())->toBe(BatchStatus::Finalizing)
        ->and($batch?->providerStatus())->toBe(BatchStatus::Finalizing->value)
        ->and($batch?->completedCount())->toBe(1)
        ->and($batch?->outputFileId())->toBe('file-output')
        ->and($batch?->completedAt()?->equalTo($completedAt))->toBeTrue();
});

it('rejects remote batch identity mutation for an existing local batch', function (): void {
    $repository = new EloquentBatchRepository;
    $repository->save(snapshot(
        id: 'batch-identity',
        status: BatchStatus::Validating,
        providerBatchId: 'remote-original',
    ));

    expect(fn () => $repository->save(snapshot(
        id: 'batch-identity',
        status: BatchStatus::InProgress,
        providerBatchId: 'remote-changed',
    )))->toThrow(BatchPersistenceException::class, 'cannot change its provider batch ID');

    expect($repository->find('batch-identity')?->providerBatchId())->toBe('remote-original');
});
