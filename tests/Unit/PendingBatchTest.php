<?php

declare(strict_types=1);

use Mockery\MockInterface;
use RefinePhp\LaravelAiBatch\Contracts\BatchProvider;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Contracts\RequestResolver;
use RefinePhp\LaravelAiBatch\Data\BatchSubmission;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Data\ResolvedProviderRequest;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;
use RefinePhp\LaravelAiBatch\Exceptions\BatchAlreadySubmittedException;
use RefinePhp\LaravelAiBatch\Exceptions\DuplicateCustomIdException;
use RefinePhp\LaravelAiBatch\Exceptions\ProviderMismatchException;
use RefinePhp\LaravelAiBatch\PendingBatch;

function foundationProviderBatch(string $id): ProviderBatch
{
    return new ProviderBatch(
        id: $id,
        provider: 'openai',
        providerBatchId: 'batch_remote',
        name: null,
        status: BatchStatus::Validating,
        providerStatus: 'validating',
        inputFileId: 'file_input',
        outputFileId: null,
        errorFileId: null,
        requestCount: 1,
        completedCount: 0,
        failedCount: 0,
    );
}

it('submits resolved requests once and persists the returned snapshot', function (): void {
    $resolver = mock(RequestResolver::class);
    $provider = mock(BatchProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('submit')->once()->withArgs(function (BatchSubmission $submission): bool {
            return $submission->provider() === 'openai'
                && $submission->requests()[0]->customId() === 'item-1';
        })->andReturnUsing(fn (BatchSubmission $submission): ProviderBatch => foundationProviderBatch($submission->id()));
    });
    $repository = mock(BatchRepository::class, function (MockInterface $mock): void {
        $mock->shouldReceive('save')->once()->with(Mockery::type(ProviderBatch::class));
    });
    $pending = new PendingBatch('openai', $resolver, $provider, $repository);
    $request = new ResolvedProviderRequest('openai', 'POST', '/v1/responses', ['model' => 'gpt-test']);

    $batch = $pending->addRequest('item-1', $request)->submit();

    expect($batch->providerBatchId())->toBe('batch_remote');
    expect(fn () => $pending->submit())->toThrow(BatchAlreadySubmittedException::class);
});

it('rejects duplicate ids and provider mismatches before submission', function (): void {
    $pending = new PendingBatch(
        'openai',
        mock(RequestResolver::class),
        mock(BatchProvider::class),
        mock(BatchRepository::class),
    );
    $request = new ResolvedProviderRequest('openai', 'POST', '/v1/responses', ['model' => 'gpt-test']);

    $pending->addRequest('same', $request);
    expect(fn () => $pending->addRequest('same', $request))->toThrow(DuplicateCustomIdException::class);

    $other = new ResolvedProviderRequest('other', 'POST', '/v1/responses', ['model' => 'gpt-test']);
    expect(fn () => (new PendingBatch(
        'openai',
        mock(RequestResolver::class),
        mock(BatchProvider::class),
        mock(BatchRepository::class),
    ))->addRequest('item', $other))->toThrow(ProviderMismatchException::class);
});
