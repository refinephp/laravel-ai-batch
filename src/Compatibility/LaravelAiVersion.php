<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Compatibility;

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Prompts\AgentPrompt;
use RefinePhp\LaravelAiBatch\Exceptions\UnsupportedLaravelAiVersionException;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

/**
 * Asserts that the installed Laravel AI release still exposes the internal
 * request building surface that batch request resolution drives.
 *
 * Laravel AI is pre-1.0 and neither `OpenAiGateway::buildStepBody()` nor
 * `AgentPrompt::__construct()` is public API, so their shape is asserted
 * structurally instead of pinning one exact version. Parameters appended with
 * defaults are tolerated, because the resolver only passes the leading
 * parameters positionally. A rename, a reorder, or a new required parameter is
 * rejected, because the resolver cannot satisfy it.
 *
 * @internal
 */
final class LaravelAiVersion
{
    /**
     * The leading `OpenAiGateway::buildStepBody()` parameters the capturing gateway forwards.
     *
     * @var list<string>
     */
    private const BUILD_STEP_BODY_PARAMETERS = [
        'provider',
        'model',
        'instructions',
        'messages',
        'tools',
        'schema',
        'options',
        'stepContext',
    ];

    /**
     * The leading `AgentPrompt::__construct()` parameters the resolver passes positionally.
     *
     * @var list<string>
     */
    private const AGENT_PROMPT_PARAMETERS = [
        'agent',
        'prompt',
        'attachments',
        'provider',
        'model',
        'timeout',
    ];

    private static bool $supported = false;

    /**
     * @throws UnsupportedLaravelAiVersionException
     */
    public static function assertSupported(): void
    {
        if (self::$supported) {
            return;
        }

        self::assertLeadingParameters(OpenAiGateway::class, 'buildStepBody', self::BUILD_STEP_BODY_PARAMETERS);
        self::assertLeadingParameters(AgentPrompt::class, '__construct', self::AGENT_PROMPT_PARAMETERS);

        self::$supported = true;
    }

    /**
     * Forget the memoized compatibility result.
     *
     * @internal
     */
    public static function flush(): void
    {
        self::$supported = false;
    }

    /**
     * Assert that a method still begins with the parameters this package passes positionally.
     *
     * @param  list<string>  $expected
     *
     * @throws UnsupportedLaravelAiVersionException
     *
     * @internal
     */
    public static function assertLeadingParameters(string $class, string $method, array $expected): void
    {
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            throw new UnsupportedLaravelAiVersionException(sprintf(
                'The installed Laravel AI release does not expose [%s::%s()], which batch request '.
                'resolution depends on. Pin laravel/ai to a release this package supports.',
                $class,
                $method,
            ));
        }

        $parameters = $reflection->getParameters();

        $actual = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        );

        if (array_slice($actual, 0, count($expected)) !== $expected) {
            throw new UnsupportedLaravelAiVersionException(sprintf(
                'The installed Laravel AI release changed the [%s::%s()] signature that batch request '.
                'resolution depends on. Expected the leading parameters [%s] but found [%s]. Pin '.
                'laravel/ai to a release this package supports, or upgrade refinephp/laravel-ai-batch.',
                $class,
                $method,
                implode(', ', $expected),
                implode(', ', $actual),
            ));
        }

        foreach (array_slice($parameters, count($expected)) as $parameter) {
            if ($parameter->isOptional()) {
                continue;
            }

            throw new UnsupportedLaravelAiVersionException(sprintf(
                'The installed Laravel AI release added a required [$%s] parameter to [%s::%s()], which '.
                'batch request resolution cannot satisfy. Pin laravel/ai to a release this package '.
                'supports, or upgrade refinephp/laravel-ai-batch.',
                $parameter->getName(),
                $class,
                $method,
            ));
        }
    }
}
