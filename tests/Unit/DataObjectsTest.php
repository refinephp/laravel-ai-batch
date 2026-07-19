<?php

declare(strict_types=1);

use RefinePhp\LaravelAiBatch\Data\BatchError;
use RefinePhp\LaravelAiBatch\Data\BatchRequest;
use RefinePhp\LaravelAiBatch\Data\BatchResult;
use RefinePhp\LaravelAiBatch\Data\BatchSubmission;
use RefinePhp\LaravelAiBatch\Data\ResolvedProviderRequest;
use RefinePhp\LaravelAiBatch\Exceptions\DuplicateCustomIdException;
use RefinePhp\LaravelAiBatch\Exceptions\EmptyBatchException;
use RefinePhp\LaravelAiBatch\Exceptions\InvalidBatchRequestException;

function resolvedRequest(): ResolvedProviderRequest
{
    return new ResolvedProviderRequest('openai', 'POST', '/v1/responses', ['model' => 'gpt-test']);
}

it('preserves custom ids and immutable provider request data', function (): void {
    $request = new BatchRequest(' pr-123 ', resolvedRequest());
    $submission = new BatchSubmission('local-id', 'openai', 'summaries', '24h', [$request]);

    expect($request->customId())->toBe(' pr-123 ')
        ->and($submission->requests())->toBe([$request])
        ->and($submission->requestCount())->toBe(1)
        ->and($request->request()->body())->toBe(['model' => 'gpt-test']);
});

it('rejects empty and duplicate submissions', function (): void {
    expect(fn () => new BatchSubmission('local-id', 'openai', null, '24h', []))
        ->toThrow(EmptyBatchException::class);

    expect(fn () => new BatchSubmission('local-id', 'openai', null, '24h', [
        new BatchRequest('same', resolvedRequest()),
        new BatchRequest('same', resolvedRequest()),
    ]))->toThrow(DuplicateCustomIdException::class);
});

it('requires exactly one result outcome', function (): void {
    $error = new BatchError('item-1', 'failed', 'The request failed.');

    expect(new BatchResult('item-1', 'request-1', 400, null, $error))->successful()->toBeFalse()
        ->and(new BatchResult('item-2', 'request-2', 200, ['id' => 'response-2'], null))->successful()->toBeTrue();

    expect(fn () => new BatchResult('item-3', null, null, null, null))
        ->toThrow(InvalidBatchRequestException::class);
});
