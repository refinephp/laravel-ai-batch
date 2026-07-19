<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchClient;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiTransportException;

it('redacts configured credentials and bearer values from HTTP error exceptions', function (): void {
    $apiKey = 'quality-test-secret-key';

    Http::fake([
        '*' => Http::response([
            'error' => [
                'code' => 'invalid_api_key',
                'message' => "Rejected {$apiKey}\nAuthorization: Bearer echoed-provider-token\nCookie: session=echoed-cookie\nhttps://example.test/?api_key=echoed-query-key",
            ],
        ], 401, ['x-request-id' => 'req-quality-auth']),
    ]);

    $client = new OpenAiBatchClient(app(Factory::class), [
        'api_key' => $apiKey,
        'base_url' => 'https://api.openai.quality.test/v1',
    ]);

    try {
        $client->retrieveBatch('batch-quality-auth');
        throw new RuntimeException('Expected authentication failure.');
    } catch (OpenAiTransportException $exception) {
        expect($exception->operation())->toBe('retrieve')
            ->and($exception->statusCode())->toBe(401)
            ->and($exception->providerCode())->toBe('invalid_api_key')
            ->and($exception->requestId())->toBe('req-quality-auth')
            ->and($exception->getMessage())->not->toContain($apiKey)
            ->and($exception->getMessage())->not->toContain('echoed-provider-token')
            ->and($exception->getMessage())->not->toContain('echoed-cookie')
            ->and($exception->getMessage())->not->toContain('echoed-query-key')
            ->and($exception->getMessage())->toContain('[REDACTED]');
    }

    Http::assertSentCount(1);
});

it('surfaces rate limits and connection failures without retrying unsafe or unconfigured requests', function (): void {
    Http::fake([
        'api.openai.quality.test/v1/batches/rate-limited' => Http::response([
            'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Try again later.'],
        ], 429),
        'api.openai.quality.test/v1/batches/timed-out' => Http::failedConnection(),
    ]);

    $client = new OpenAiBatchClient(app(Factory::class), [
        'api_key' => 'quality-test-key',
        'base_url' => 'https://api.openai.quality.test/v1',
        'connect_timeout' => 1,
        'request_timeout' => 1,
    ]);

    try {
        $client->retrieveBatch('rate-limited');
        throw new RuntimeException('Expected rate-limit failure.');
    } catch (OpenAiTransportException $exception) {
        expect($exception->statusCode())->toBe(429)
            ->and($exception->providerCode())->toBe('rate_limit_exceeded');
    }

    try {
        $client->retrieveBatch('timed-out');
        throw new RuntimeException('Expected connection failure.');
    } catch (OpenAiTransportException $exception) {
        expect($exception->statusCode())->toBeNull()
            ->and($exception->operation())->toBe('retrieve')
            ->and($exception->clientRequestId())->not->toBeNull();
    }

    Http::assertSentCount(2);
});
