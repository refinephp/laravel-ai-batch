<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use RefinePhp\LaravelAiBatch\Data\BatchRequest;
use RefinePhp\LaravelAiBatch\Data\BatchSubmission;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Data\ResolvedProviderRequest;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;
use RefinePhp\LaravelAiBatch\Exceptions\AmbiguousBatchSubmissionException;
use RefinePhp\LaravelAiBatch\Exceptions\BatchCancellationException;
use RefinePhp\LaravelAiBatch\Exceptions\BatchResultRetrievalException;
use RefinePhp\LaravelAiBatch\Exceptions\MalformedBatchOutputException;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchClient;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchProvider;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlParser;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlWriter;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiStatusMapper;

/** @param array<string, mixed> $clientConfig */
function openAiProvider(array $clientConfig = []): OpenAiBatchProvider
{
    return new OpenAiBatchProvider(
        client: new OpenAiBatchClient(app(Factory::class), array_merge([
            'api_key' => 'test-key',
            'base_url' => 'https://api.openai.test/v1',
        ], $clientConfig)),
        writer: new OpenAiJsonlWriter,
        statusMapper: new OpenAiStatusMapper,
        parser: new OpenAiJsonlParser,
    );
}

function openAiProviderBatch(
    BatchStatus $status = BatchStatus::Completed,
    ?string $outputFileId = 'file-output',
    ?string $errorFileId = 'file-errors',
): ProviderBatch {
    return new ProviderBatch(
        id: 'local-123',
        provider: 'openai',
        providerBatchId: 'batch-123',
        name: 'Provider test',
        status: $status,
        providerStatus: $status->value,
        inputFileId: 'file-input',
        outputFileId: $outputFileId,
        errorFileId: $errorFileId,
        requestCount: 3,
        completedCount: 1,
        failedCount: 2,
    );
}

function openAiProviderFixture(string $name): string
{
    $content = file_get_contents(dirname(__DIR__, 3).'/Fixtures/OpenAI/'.$name);

    if ($content === false) {
        throw new RuntimeException("OpenAI provider fixture [{$name}] could not be read.");
    }

    return $content;
}

it('uploads JSONL and creates a 24 hour responses batch', function () {
    Http::fake([
        'api.openai.test/v1/files' => Http::response(['id' => 'file-input'], 200),
        'api.openai.test/v1/batches' => Http::response([
            'id' => 'batch-123',
            'status' => 'validating',
            'endpoint' => '/v1/responses',
            'model' => 'gpt-5-mini',
            'input_file_id' => 'file-input',
            'created_at' => 1_784_476_800,
        ], 200),
    ]);

    $request = new BatchRequest('item-1', new ResolvedProviderRequest(
        provider: 'openai',
        method: 'POST',
        endpoint: '/v1/responses',
        body: ['model' => 'gpt-5-mini', 'input' => 'Do the work.'],
    ));
    $submission = new BatchSubmission('local-123', 'openai', 'Provider test', '24h', [$request]);
    $batch = openAiProvider()->submit($submission);

    expect($batch->id())->toBe('local-123')
        ->and($batch->providerBatchId())->toBe('batch-123')
        ->and($batch->status())->toBe(BatchStatus::Validating)
        ->and($batch->inputFileId())->toBe('file-input')
        ->and($batch->requestCount())->toBe(1);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/batches'
        && $request['input_file_id'] === 'file-input'
        && $request['endpoint'] === '/v1/responses'
        && $request['completion_window'] === '24h'
        && $request['metadata']['application_batch_id'] === 'local-123');
});

it('does not retry an ambiguous create and retains safe recovery identifiers', function () {
    Http::fake([
        'api.openai.test/v1/files' => Http::response(['id' => 'file-input-recovery'], 200),
        'api.openai.test/v1/batches' => Http::failedConnection(),
    ]);

    $submission = new BatchSubmission('local-recovery', 'openai', null, '24h', [
        new BatchRequest('item-recovery', new ResolvedProviderRequest(
            provider: 'openai',
            method: 'POST',
            endpoint: '/v1/responses',
            body: ['model' => 'gpt-5-mini', 'input' => 'Do the work.'],
        )),
    ]);

    expect(fn () => openAiProvider()->submit($submission))
        ->toThrow(AmbiguousBatchSubmissionException::class, 'file-input-recovery');

    Http::assertSentCount(2);
});

it('combines output and error files by custom ID without relying on order', function () {
    Http::fake([
        'api.openai.test/v1/files/file-output/content' => Http::response(openAiProviderFixture('output.jsonl'), 200),
        'api.openai.test/v1/files/file-errors/content' => Http::response(openAiProviderFixture('errors.jsonl'), 200),
    ]);

    $results = iterator_to_array(openAiProvider()->results(openAiProviderBatch()));

    expect(array_map(fn ($result) => $result->customId(), $results))->toBe([
        'item-success',
        'item-http-error',
        'item-expired',
    ])->and(array_filter($results, fn ($result) => ! $result->successful()))->toHaveCount(2);
});

it('treats nonterminal results as not ready and completed missing file IDs as malformed', function () {
    expect(fn () => iterator_to_array(openAiProvider()->results(openAiProviderBatch(
        status: BatchStatus::InProgress,
    ))))->toThrow(BatchResultRetrievalException::class, 'not ready');

    expect(fn () => iterator_to_array(openAiProvider()->results(openAiProviderBatch(
        outputFileId: null,
        errorFileId: null,
    ))))->toThrow(MalformedBatchOutputException::class, 'expected 3 result records but received 0');

    Http::assertNothingSent();
});

it('rejects duplicate correlations across output and error files', function () {
    $duplicate = "{\"custom_id\":\"same\",\"response\":{\"status_code\":200,\"request_id\":\"req\",\"body\":{\"ok\":true}},\"error\":null}\n";

    Http::fake([
        'api.openai.test/v1/files/file-output/content' => Http::response($duplicate, 200),
        'api.openai.test/v1/files/file-errors/content' => Http::response($duplicate, 200),
    ]);

    expect(fn () => iterator_to_array(openAiProvider()->results(openAiProviderBatch())))
        ->toThrow(MalformedBatchOutputException::class, 'same custom ID');
});

it('does not send cancellation for a terminal batch', function () {
    expect(fn () => openAiProvider()->cancel(openAiProviderBatch()))
        ->toThrow(BatchCancellationException::class, 'not in a cancellable state');

    Http::assertNothingSent();
});
