<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\OpenAiProvider;
use RefinePhp\LaravelAiBatch\Contracts\RequestResolver;
use RefinePhp\LaravelAiBatch\Exceptions\RequestResolutionException;
use RefinePhp\LaravelAiBatch\Exceptions\UnsupportedBatchFeatureException;
use RefinePhp\LaravelAiBatch\Tests\Fixtures\Agents\ParityAgent;
use RefinePhp\LaravelAiBatch\Tests\Fixtures\Middleware\RevisePrompt;
use RefinePhp\LaravelAiBatch\Tests\Fixtures\Middleware\ShortCircuit;

function requestResolver(): RequestResolver
{
    return app(RequestResolver::class);
}

/** @return array<string, mixed> */
function successfulOpenAiResponse(): array
{
    return [
        'id' => 'resp_resolution_test',
        'status' => 'completed',
        'model' => 'gpt-5.4-mini',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => '{"summary":"Resolved."}',
            ]],
        ]],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ];
}

test('captures the exact initial OpenAI request without transport', function () {
    Http::preventStrayRequests();

    $agent = new ParityAgent([new RevisePrompt]);
    $attachments = [new Base64Document(base64_encode('fixture document'), 'text/plain')];

    $resolved = requestResolver()->resolve(
        $agent,
        'Summarize this document.',
        Lab::OpenAI,
        $attachments,
        'gpt-5.4-mini-explicit',
    );

    Http::assertNothingSent();

    expect($resolved->provider())->toBe('openai')
        ->and($resolved->method())->toBe('POST')
        ->and($resolved->endpoint())->toBe('/v1/responses')
        ->and($resolved->headers())->toBe([])
        ->and($resolved->body()['model'])->toBe('gpt-5.4-mini-explicit')
        ->and($resolved->body()['max_output_tokens'])->toBe(321)
        ->and($resolved->body()['temperature'])->toBe(0.25)
        ->and($resolved->body()['top_p'])->toBe(0.8)
        ->and($resolved->body()['metadata'])->toBe(['source' => 'request-resolution-test'])
        ->and($resolved->body()['tools'])->toHaveCount(1)
        ->and($resolved->body()['text']['format']['type'])->toBe('json_schema')
        ->and(json_encode($resolved->body(), JSON_THROW_ON_ERROR))->not->toContain('test-key');

    $encodedBody = json_encode($resolved->body(), JSON_THROW_ON_ERROR);

    expect($encodedBody)->toContain('concise fact summary')
        ->and($encodedBody)->toContain('Earlier question.')
        ->and($encodedBody)->toContain('Earlier answer.')
        ->and($encodedBody)->toContain('Added by middleware.')
        ->and($encodedBody)->toContain('input_file');

    Http::fake(['api.openai.com/*' => Http::response(successfulOpenAiResponse())]);

    $agent->prompt(
        'Summarize this document.',
        attachments: $attachments,
        provider: Lab::OpenAI,
        model: 'gpt-5.4-mini-explicit',
    );

    Http::assertSentCount(1);

    $synchronousBody = null;

    Http::assertSent(function (Request $request) use (&$synchronousBody): bool {
        $synchronousBody = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

        return true;
    });

    if (! is_array($synchronousBody)) {
        throw new RuntimeException('The synchronous OpenAI request body was not captured.');
    }

    $sharedProvider = Ai::textProvider('openai');

    if (! $sharedProvider instanceof OpenAiProvider) {
        throw new RuntimeException('The configured test provider is not native OpenAI.');
    }

    expect($resolved->body())->toBe($synchronousBody)
        ->and($sharedProvider->textGateway())->toBeInstanceOf(OpenAiGateway::class);
});

test('uses the agent model when no explicit model is supplied', function () {
    Http::preventStrayRequests();

    $resolved = requestResolver()->resolve(
        new ParityAgent(selectedModel: 'gpt-agent-selected'),
        'Use the agent model.',
        'openai',
    );

    expect($resolved->body()['model'])->toBe('gpt-agent-selected');
    Http::assertNothingSent();
});

test('rejects active Laravel AI agent fakes', function () {
    ParityAgent::fake(['A fake response']);

    expect(fn () => requestResolver()->resolve(new ParityAgent, 'Prompt', 'openai'))
        ->toThrow(UnsupportedBatchFeatureException::class, 'is faked');
});

test('rejects middleware that short circuits before the provider request', function () {
    Http::preventStrayRequests();

    expect(fn () => requestResolver()->resolve(
        new ParityAgent([new ShortCircuit]),
        'Prompt',
        'openai',
    ))->toThrow(RequestResolutionException::class, 'short-circuiting middleware');

    Http::assertNothingSent();
});

test('rejects non OpenAI provider connections', function () {
    config(['ai.providers.anthropic' => [
        'driver' => 'anthropic',
        'key' => 'not-a-real-key',
        'url' => 'https://api.anthropic.com/v1',
    ]]);

    expect(fn () => requestResolver()->resolve(new ParityAgent, 'Prompt', 'anthropic'))
        ->toThrow(UnsupportedBatchFeatureException::class, 'not Laravel AI\'s native OpenAI provider');
});

test('rejects native OpenAI connections with a custom base URL', function () {
    config(['ai.providers.proxy' => [
        'driver' => 'openai',
        'key' => 'not-a-real-key',
        'url' => 'https://proxy.example.test/v1',
        'store' => false,
    ]]);

    expect(fn () => requestResolver()->resolve(new ParityAgent, 'Prompt', 'proxy'))
        ->toThrow(UnsupportedBatchFeatureException::class, 'custom OpenAI base URL');
});

test('rejects a model removed by provider options', function () {
    Http::preventStrayRequests();

    expect(fn () => requestResolver()->resolve(
        new ParityAgent(bodyModelOverride: ''),
        'Prompt',
        'openai',
    ))->toThrow(RequestResolutionException::class, 'without a valid model');

    Http::assertNothingSent();
});
