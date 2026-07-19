<?php

declare(strict_types=1);

use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlException;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlParser;

function openAiParserFixture(string $name): string
{
    $content = file_get_contents(dirname(__DIR__, 3).'/Fixtures/OpenAI/'.$name);

    if ($content === false) {
        throw new RuntimeException("OpenAI parser fixture [{$name}] could not be read.");
    }

    return $content;
}

it('parses successful HTTP failed and execution failed records', function () {
    $parser = new OpenAiJsonlParser;
    $success = iterator_to_array($parser->parse(openAiParserFixture('output.jsonl'), 'file-output'));
    $errors = iterator_to_array($parser->parse(openAiParserFixture('errors.jsonl'), 'file-errors'));
    $response = $success[0]->response();
    $httpError = $errors[0]->error();
    $executionError = $errors[1]->error();

    if ($response === null || $httpError === null || $executionError === null) {
        throw new RuntimeException('The OpenAI parser returned an unexpected result shape.');
    }

    expect($success)->toHaveCount(1)
        ->and($success[0]->successful())->toBeTrue()
        ->and($success[0]->customId())->toBe('item-success')
        ->and($success[0]->providerRequestId())->toBe('req_success')
        ->and($response['id'] ?? null)->toBe('resp_success')
        ->and($errors)->toHaveCount(2)
        ->and($errors[0]->successful())->toBeFalse()
        ->and($errors[0]->statusCode())->toBe(429)
        ->and($httpError->code())->toBe('rate_limit_exceeded')
        ->and($executionError->code())->toBe('batch_expired')
        ->and($errors[1]->statusCode())->toBeNull();
});

it('rejects partial blank duplicate and structurally invalid records', function (string $content, ?int $line) {
    try {
        iterator_to_array((new OpenAiJsonlParser)->parse($content, 'file-bad'));
        throw new RuntimeException('Expected malformed JSONL to be rejected.');
    } catch (OpenAiJsonlException $exception) {
        expect($exception->fileId())->toBe('file-bad')
            ->and($exception->line())->toBe($line)
            ->and($exception->getMessage())->not->toContain($content);
    }
})->with([
    'partial final record' => ['{"custom_id":"partial"}', null],
    'blank record' => ["\n", 1],
    'duplicate ID' => ["{\"custom_id\":\"same\",\"response\":null,\"error\":{\"code\":\"x\"}}\n{\"custom_id\":\"same\",\"response\":null,\"error\":{\"code\":\"x\"}}\n", 2],
    'both response and error' => ["{\"custom_id\":\"same\",\"response\":{},\"error\":{}}\n", 1],
]);
