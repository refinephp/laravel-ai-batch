<?php

declare(strict_types=1);

use RefinePhp\LaravelAiBatch\Data\BatchRequest;
use RefinePhp\LaravelAiBatch\Data\ResolvedProviderRequest;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlException;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlWriter;

/** @param array<string, string> $headers */
function openAiBatchRequest(
    string $customId,
    string $model = 'gpt-5-mini',
    string $endpoint = '/v1/responses',
    array $headers = [],
): BatchRequest {
    return new BatchRequest($customId, new ResolvedProviderRequest(
        provider: 'openai',
        method: 'POST',
        endpoint: $endpoint,
        body: ['model' => $model, 'input' => 'Résumé ✓'],
        headers: $headers,
    ));
}

it('writes deterministic compact unicode JSONL with a final newline', function () {
    $file = (new OpenAiJsonlWriter)->write([
        openAiBatchRequest('first'),
        openAiBatchRequest('second'),
    ], 'openai');

    try {
        $content = file_get_contents($file->path());

        if ($content === false) {
            throw new RuntimeException('The generated JSONL file could not be read.');
        }

        expect($content)
            ->toBe("{\"custom_id\":\"first\",\"method\":\"POST\",\"url\":\"/v1/responses\",\"body\":{\"model\":\"gpt-5-mini\",\"input\":\"Résumé ✓\"}}\n{\"custom_id\":\"second\",\"method\":\"POST\",\"url\":\"/v1/responses\",\"body\":{\"model\":\"gpt-5-mini\",\"input\":\"Résumé ✓\"}}\n")
            ->and($file->requestCount())->toBe(2)
            ->and($file->bytes())->toBe(strlen($content))
            ->and($file->endpoint())->toBe('/v1/responses')
            ->and($file->model())->toBe('gpt-5-mini');
    } finally {
        $path = $file->path();
        $file->delete();

        expect($path)->not->toBeFile();
    }
});

it('rejects unsupported endpoints mixed models and per-request headers', function (array $requests, string $message) {
    expect(fn () => (new OpenAiJsonlWriter)->write($requests, 'openai'))
        ->toThrow(OpenAiJsonlException::class, $message);
})->with([
    'unsupported endpoint' => [[openAiBatchRequest('one', endpoint: '/v1/chat/completions')], 'only the OpenAI /v1/responses'],
    'mixed models' => [[openAiBatchRequest('one'), openAiBatchRequest('two', model: 'gpt-5')], 'same model'],
    'headers' => [[openAiBatchRequest('one', headers: ['X-Test' => 'value'])], 'per-request headers'],
]);

it('enforces request and byte limits before returning an input file', function () {
    expect(fn () => (new OpenAiJsonlWriter(maxRequests: 1))->write([
        openAiBatchRequest('one'),
        openAiBatchRequest('two'),
    ], 'openai'))->toThrow(OpenAiJsonlException::class, 'at most 1 requests');

    expect(fn () => (new OpenAiJsonlWriter(maxBytes: 10))->write([
        openAiBatchRequest('one'),
    ], 'openai'))->toThrow(OpenAiJsonlException::class, 'at most 10 bytes');
});
