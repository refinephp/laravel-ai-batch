<?php

declare(strict_types=1);

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Prompts\AgentPrompt;
use RefinePhp\LaravelAiBatch\Compatibility\LaravelAiVersion;
use RefinePhp\LaravelAiBatch\Exceptions\UnsupportedLaravelAiVersionException;
use RefinePhp\LaravelAiBatch\Tests\Fixtures\Compatibility\SignatureShapes;

beforeEach(function () {
    LaravelAiVersion::flush();
});

afterAll(function () {
    LaravelAiVersion::flush();
});

test('accepts the installed Laravel AI request building surface', function () {
    LaravelAiVersion::assertSupported();

    expect(true)->toBeTrue();
});

test('memoizes a successful compatibility assertion', function () {
    LaravelAiVersion::assertSupported();
    LaravelAiVersion::assertSupported();

    expect(true)->toBeTrue();
});

test('still resolves the protected Laravel AI request builder contract', function () {
    $builder = new ReflectionMethod(OpenAiGateway::class, 'buildStepBody');

    expect($builder->isProtected())->toBeTrue()
        ->and(array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $builder->getParameters(),
        ))->toBe([
            'provider',
            'model',
            'instructions',
            'messages',
            'tools',
            'schema',
            'options',
            'stepContext',
        ]);
});

test('still resolves the leading Laravel AI AgentPrompt constructor contract', function () {
    $constructor = new ReflectionMethod(AgentPrompt::class, '__construct');

    $parameters = $constructor->getParameters();

    expect(array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        array_slice($parameters, 0, 6),
    ))->toBe([
        'agent',
        'prompt',
        'attachments',
        'provider',
        'model',
        'timeout',
    ]);

    foreach (array_slice($parameters, 6) as $parameter) {
        expect($parameter->isOptional())->toBeTrue();
    }
});

test('accepts a signature whose leading parameters are unchanged', function () {
    LaravelAiVersion::assertLeadingParameters(SignatureShapes::class, 'expected', ['provider', 'model']);

    expect(true)->toBeTrue();
});

test('accepts a signature that appends an optional parameter', function () {
    LaravelAiVersion::assertLeadingParameters(SignatureShapes::class, 'appendedOptional', ['provider', 'model']);

    expect(true)->toBeTrue();
});

test('rejects a signature that appends a required parameter', function () {
    expect(fn () => LaravelAiVersion::assertLeadingParameters(
        SignatureShapes::class,
        'appendedRequired',
        ['provider', 'model'],
    ))->toThrow(UnsupportedLaravelAiVersionException::class, 'added a required [$added] parameter');
});

test('rejects a signature that renames a depended-on parameter', function () {
    expect(fn () => LaravelAiVersion::assertLeadingParameters(
        SignatureShapes::class,
        'renamed',
        ['provider', 'model'],
    ))->toThrow(UnsupportedLaravelAiVersionException::class, 'changed the');
});

test('rejects a signature that reorders depended-on parameters', function () {
    expect(fn () => LaravelAiVersion::assertLeadingParameters(
        SignatureShapes::class,
        'reordered',
        ['provider', 'model'],
    ))->toThrow(UnsupportedLaravelAiVersionException::class, 'Expected the leading parameters');
});

test('rejects a signature that drops a depended-on parameter', function () {
    expect(fn () => LaravelAiVersion::assertLeadingParameters(
        SignatureShapes::class,
        'truncated',
        ['provider', 'model'],
    ))->toThrow(UnsupportedLaravelAiVersionException::class, 'Expected the leading parameters');
});

test('rejects a method Laravel AI no longer exposes', function () {
    expect(fn () => LaravelAiVersion::assertLeadingParameters(
        SignatureShapes::class,
        'removedUpstream',
        ['provider'],
    ))->toThrow(UnsupportedLaravelAiVersionException::class, 'does not expose');
});
