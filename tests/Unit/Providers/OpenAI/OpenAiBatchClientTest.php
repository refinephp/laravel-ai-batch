<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchClient;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiTransportException;

function openAiClient(): OpenAiBatchClient
{
    return new OpenAiBatchClient(app(Factory::class), [
        'api_key' => 'test-secret-key',
        'base_url' => 'https://api.openai.test/v1',
        'connect_timeout' => 2,
        'upload_timeout' => 3,
        'request_timeout' => 4,
        'download_timeout' => 5,
    ]);
}

it('performs upload create retrieve cancel and file content operations', function () {
    Http::fake([
        'api.openai.test/v1/files' => Http::response(['id' => 'file-input'], 200),
        'api.openai.test/v1/batches' => Http::response(['id' => 'batch-created'], 200),
        'api.openai.test/v1/batches/batch-created/cancel' => Http::response(['id' => 'batch-created', 'status' => 'cancelling'], 200),
        'api.openai.test/v1/batches/batch-created' => Http::response(['id' => 'batch-created', 'status' => 'in_progress'], 200),
        'api.openai.test/v1/files/file-output/content' => Http::response("{}\n", 200),
    ]);

    $path = tempnam(sys_get_temp_dir(), 'openai-client-test-');
    file_put_contents($path, "{}\n");

    try {
        $client = openAiClient();

        expect($client->uploadBatchInput($path, 'input.jsonl')['id'])->toBe('file-input')
            ->and($client->createBatch('file-input', '/v1/responses', '24h', ['application_batch_id' => 'local'])['id'])->toBe('batch-created')
            ->and($client->retrieveBatch('batch-created')['status'])->toBe('in_progress')
            ->and($client->cancelBatch('batch-created')['status'])->toBe('cancelling')
            ->and($client->retrieveFileContent('file-output'))->toBe("{}\n");
    } finally {
        @unlink($path);
    }

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://api.openai.test/v1/files') {
            return true;
        }

        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-secret-key')
            && $request->hasHeader('X-Client-Request-Id')
            && $request->hasFile('file');
    });
});

it('throws a safe structured transport exception for provider errors', function () {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'message' => "Invalid request\nwithout echoing credentials.",
                'code' => 'invalid_request',
            ],
        ], 401, ['x-request-id' => 'req_error']),
    ]);

    try {
        openAiClient()->retrieveBatch('batch-safe');
        throw new RuntimeException('Expected the provider error to be surfaced.');
    } catch (OpenAiTransportException $exception) {
        expect($exception->operation())->toBe('retrieve')
            ->and($exception->statusCode())->toBe(401)
            ->and($exception->providerCode())->toBe('invalid_request')
            ->and($exception->requestId())->toBe('req_error')
            ->and($exception->clientRequestId())->not->toBeNull()
            ->and($exception->getMessage())->toContain('Invalid request without echoing credentials.')
            ->and($exception->getMessage())->not->toContain('test-secret-key');
    }

    Http::assertSentCount(1);
});
