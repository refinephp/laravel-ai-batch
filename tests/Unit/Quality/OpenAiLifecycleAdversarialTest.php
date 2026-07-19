<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;
use RefinePhp\LaravelAiBatch\Exceptions\BatchCancellationException;
use RefinePhp\LaravelAiBatch\Exceptions\MalformedBatchOutputException;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchClient;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchProvider;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlParser;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlWriter;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiPayloadException;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiStatusMapper;

function qualityOpenAiProvider(): OpenAiBatchProvider
{
    return new OpenAiBatchProvider(
        client: new OpenAiBatchClient(app(Factory::class), [
            'api_key' => 'quality-test-key',
            'base_url' => 'https://api.openai.quality.test/v1',
        ]),
        writer: new OpenAiJsonlWriter,
        statusMapper: new OpenAiStatusMapper,
        parser: new OpenAiJsonlParser,
    );
}

function qualityProviderBatch(
    BatchStatus $status = BatchStatus::Completed,
    int $requestCount = 1,
    int $completedCount = 1,
    int $failedCount = 0,
    ?string $outputFileId = 'file-quality-output',
    ?string $errorFileId = null,
): ProviderBatch {
    return new ProviderBatch(
        id: 'local-quality',
        provider: 'openai',
        providerBatchId: 'batch-quality',
        name: null,
        status: $status,
        providerStatus: $status->value,
        inputFileId: 'file-quality-input',
        outputFileId: $outputFileId,
        errorFileId: $errorFileId,
        requestCount: $requestCount,
        completedCount: $completedCount,
        failedCount: $failedCount,
    );
}

function qualityHttpErrorRecord(string $customId): string
{
    return json_encode([
        'custom_id' => $customId,
        'response' => [
            'status_code' => 429,
            'request_id' => 'req-quality-rate-limit',
            'body' => [
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Rate limit exceeded.',
                ],
            ],
        ],
        'error' => null,
    ], JSON_THROW_ON_ERROR)."\n";
}

it('returns HTTP failures from the output file through the errors convenience API', function (): void {
    Http::fake([
        'api.openai.quality.test/v1/files/file-quality-output/content' => Http::response(
            qualityHttpErrorRecord('quality-http-failure'),
        ),
    ]);

    $errors = iterator_to_array(qualityOpenAiProvider()->errors(qualityProviderBatch(
        completedCount: 0,
        failedCount: 1,
    )));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->customId())->toBe('quality-http-failure')
        ->and($errors[0]->code())->toBe('rate_limit_exceeded');
});

it('rejects a partial completed result set after parsing available records', function (): void {
    Http::fake([
        'api.openai.quality.test/v1/files/file-quality-output/content' => Http::response(
            qualityHttpErrorRecord('quality-only-result'),
        ),
    ]);

    expect(fn (): array => iterator_to_array(qualityOpenAiProvider()->results(qualityProviderBatch(
        requestCount: 2,
        completedCount: 1,
        failedCount: 1,
    ))))->toThrow(MalformedBatchOutputException::class, 'expected 2 result records but received 1');
});

it('rejects missing expired and cancelled outcomes while exempting validation-failed batches', function (BatchStatus $status): void {
    expect(fn (): array => iterator_to_array(qualityOpenAiProvider()->results(qualityProviderBatch(
        status: $status,
        requestCount: 1,
        completedCount: 0,
        failedCount: 1,
        outputFileId: null,
        errorFileId: null,
    ))))->toThrow(MalformedBatchOutputException::class, 'expected 1 result records but received 0');

    expect(iterator_to_array(qualityOpenAiProvider()->results(qualityProviderBatch(
        status: BatchStatus::Failed,
        requestCount: 1,
        completedCount: 0,
        failedCount: 1,
        outputFileId: null,
        errorFileId: null,
    ))))->toBe([]);
})->with([
    'expired' => BatchStatus::Expired,
    'cancelled' => BatchStatus::Cancelled,
]);

it('rejects a provider snapshot that changes the remote batch identity', function (): void {
    $previous = qualityProviderBatch(status: BatchStatus::InProgress);

    expect(fn () => (new OpenAiStatusMapper)->map([
        'id' => 'batch-different',
        'status' => 'completed',
    ], 'local-quality', 'openai', null, previous: $previous))
        ->toThrow(OpenAiPayloadException::class, 'unexpected batch ID');
});

it('does not regress the locally known request and outcome counts', function (): void {
    $previous = qualityProviderBatch(
        status: BatchStatus::InProgress,
        requestCount: 3,
        completedCount: 2,
        failedCount: 0,
    );

    $batch = (new OpenAiStatusMapper)->map([
        'id' => 'batch-quality',
        'status' => 'in_progress',
        'request_counts' => ['total' => 0, 'completed' => 0, 'failed' => 0],
    ], 'local-quality', 'openai', null, previous: $previous);

    expect($batch->requestCount())->toBe(3)
        ->and($batch->completedCount())->toBe(2)
        ->and($batch->failedCount())->toBe(0);
});

it('rejects backward nonterminal and changed terminal lifecycle statuses', function (): void {
    $mapper = new OpenAiStatusMapper;

    expect(fn () => $mapper->map([
        'id' => 'batch-quality',
        'status' => 'validating',
    ], 'local-quality', 'openai', null, previous: qualityProviderBatch(
        status: BatchStatus::InProgress,
    )))->toThrow(OpenAiPayloadException::class, 'invalid lifecycle status transition')
        ->and(fn () => $mapper->map([
            'id' => 'batch-quality',
            'status' => 'in_progress',
        ], 'local-quality', 'openai', null, previous: qualityProviderBatch(
            status: BatchStatus::Completed,
        )))->toThrow(OpenAiPayloadException::class, 'invalid lifecycle status transition');
});

it('reconciles a lost cancellation response by retrieving the remote batch once', function (): void {
    Http::fake([
        'api.openai.quality.test/v1/batches/batch-quality/cancel' => Http::failedConnection(),
        'api.openai.quality.test/v1/batches/batch-quality' => Http::response([
            'id' => 'batch-quality',
            'status' => 'cancelling',
            'request_counts' => ['total' => 1, 'completed' => 0, 'failed' => 0],
        ]),
    ]);

    $batch = qualityOpenAiProvider()->cancel(qualityProviderBatch(
        status: BatchStatus::InProgress,
        completedCount: 0,
    ));

    expect($batch->status())->toBe(BatchStatus::Cancelling);
    Http::assertSentCount(2);
});

it('does not report a lost cancellation as accepted when reconciliation remains active', function (): void {
    Http::fake([
        'api.openai.quality.test/v1/batches/batch-quality/cancel' => Http::failedConnection(),
        'api.openai.quality.test/v1/batches/batch-quality' => Http::response([
            'id' => 'batch-quality',
            'status' => 'in_progress',
            'request_counts' => ['total' => 1, 'completed' => 0, 'failed' => 0],
        ]),
    ]);

    expect(fn () => qualityOpenAiProvider()->cancel(qualityProviderBatch(
        status: BatchStatus::InProgress,
        completedCount: 0,
    )))->toThrow(BatchCancellationException::class, 'acceptance could not be confirmed');

    Http::assertSentCount(2);
});
