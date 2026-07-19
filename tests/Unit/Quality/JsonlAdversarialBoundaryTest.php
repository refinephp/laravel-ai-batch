<?php

declare(strict_types=1);

use RefinePhp\LaravelAiBatch\Data\BatchRequest;
use RefinePhp\LaravelAiBatch\Data\ResolvedProviderRequest;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlException;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlParser;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlWriter;

function qualityBatchRequest(string $customId, string $input): BatchRequest
{
    return new BatchRequest($customId, new ResolvedProviderRequest(
        provider: 'openai',
        method: 'POST',
        endpoint: '/v1/responses',
        body: ['model' => 'gpt-5-mini', 'input' => $input],
    ));
}

it('uses a private temporary file and removes it after explicit cleanup', function (): void {
    $file = (new OpenAiJsonlWriter)->write([
        qualityBatchRequest('quality-temp', 'Sensitive prompt text.'),
    ], 'openai');

    $path = $file->path();
    $permissions = fileperms($path);

    try {
        expect($path)->toBeFile()
            ->and($permissions)->not->toBeFalse()
            ->and(($permissions & 0o077))->toBe(0)
            ->and(basename($path))->toStartWith('laravel-ai-batch-openai-')
            ->and($file->filename())->toMatch('/^batch-input-[a-f0-9]{24}\.jsonl$/');
    } finally {
        $file->delete();
    }

    expect($path)->not->toBeFile();
});

it('removes partial temporary files and does not echo invalid prompt bytes', function (): void {
    $before = glob(sys_get_temp_dir().'/laravel-ai-batch-openai-*') ?: [];
    $invalidPrompt = "prompt-secret-\xB1\x31";

    try {
        (new OpenAiJsonlWriter)->write([
            qualityBatchRequest('quality-invalid-unicode', $invalidPrompt),
        ], 'openai');

        throw new RuntimeException('Expected invalid UTF-8 to be rejected.');
    } catch (OpenAiJsonlException $exception) {
        expect($exception->line())->toBe(1)
            ->and($exception->getMessage())->toBe('OpenAI batch request 1 could not be encoded as JSON.')
            ->and($exception->getMessage())->not->toContain('prompt-secret');
    }

    $after = glob(sys_get_temp_dir().'/laravel-ai-batch-openai-*') ?: [];

    sort($before);
    sort($after);

    expect($after)->toBe($before);
});

it('parses large out-of-order unicode output without normalizing custom IDs', function (): void {
    $records = [];

    for ($index = 1499; $index >= 0; $index--) {
        $customId = $index === 777 ? "résumé-✓-{$index}" : "quality-{$index}";
        $records[] = json_encode([
            'custom_id' => $customId,
            'response' => [
                'status_code' => 200,
                'request_id' => "req-{$index}",
                'body' => ['index' => $index],
            ],
            'error' => null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $results = iterator_to_array((new OpenAiJsonlParser)->parse(implode("\n", $records)."\n", 'file-quality-large'));

    expect($results)->toHaveCount(1500)
        ->and($results[0]->customId())->toBe('quality-1499')
        ->and($results[1499]->customId())->toBe('quality-0')
        ->and(array_map(static fn ($result): string => $result->customId(), $results))
        ->toContain('résumé-✓-777');
});

it('rejects malformed unicode and partial JSONL without exposing the record', function (string $content): void {
    try {
        iterator_to_array((new OpenAiJsonlParser)->parse($content, 'file-quality-malformed'));
        throw new RuntimeException('Expected malformed provider output.');
    } catch (OpenAiJsonlException $exception) {
        expect($exception->fileId())->toBe('file-quality-malformed')
            ->and($exception->getMessage())->not->toContain('provider-output-secret');
    }
})->with([
    'invalid unicode' => ["{\"custom_id\":\"provider-output-secret-\xB1\",\"error\":{},\"response\":null}\n"],
    'partial final line' => ['{"custom_id":"provider-output-secret"}'],
]);
