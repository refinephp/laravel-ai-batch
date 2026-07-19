<?php

declare(strict_types=1);

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Prompts\AgentPrompt;
use RefinePhp\LaravelAiBatch\Compatibility\LaravelAiVersion;
use RefinePhp\LaravelAiBatch\Exceptions\UnsupportedLaravelAiVersionException;

test('pins request resolution to Laravel AI 0.9.1 exactly', function () {
    expect(fn () => LaravelAiVersion::assertSupported('0.9.2.0'))
        ->toThrow(UnsupportedLaravelAiVersionException::class, 'requires exactly version [0.9.1]');

    LaravelAiVersion::assertSupported('0.9.1.0');
    expect(true)->toBeTrue();
});

test('matches the protected Laravel AI 0.9.1 request builder contract', function () {
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

test('matches the Laravel AI 0.9.1 AgentPrompt constructor contract', function () {
    $constructor = new ReflectionMethod(AgentPrompt::class, '__construct');

    expect(array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor->getParameters(),
    ))->toBe([
        'agent',
        'prompt',
        'attachments',
        'provider',
        'model',
        'timeout',
        'invocationId',
    ]);
});
