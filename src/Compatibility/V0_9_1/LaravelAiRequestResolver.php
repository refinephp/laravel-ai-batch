<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Compatibility\V0_9_1;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Timeout as TimeoutAttribute;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\OpenAiProvider;
use RefinePhp\LaravelAiBatch\Compatibility\LaravelAiVersion;
use RefinePhp\LaravelAiBatch\Contracts\RequestResolver;
use RefinePhp\LaravelAiBatch\Data\ResolvedProviderRequest;
use RefinePhp\LaravelAiBatch\Exceptions\RequestResolutionException;
use RefinePhp\LaravelAiBatch\Exceptions\UnsupportedBatchFeatureException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * Resolves the initial provider request through Laravel AI's native OpenAI
 * request builder without allowing the provider transport to run.
 *
 * The request building surface this drives is not public Laravel AI API, so
 * {@see LaravelAiVersion::assertSupported()} verifies its shape before use.
 */
final class LaravelAiRequestResolver implements RequestResolver
{
    private const OPENAI_BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        private readonly AiManager $ai,
        private readonly Dispatcher $events,
    ) {}

    public function resolve(
        Agent $agent,
        string $prompt,
        Lab|string $provider,
        array $attachments = [],
        ?string $model = null,
    ): ResolvedProviderRequest {
        LaravelAiVersion::assertSupported();

        $connection = $provider instanceof Lab ? $provider->value : $provider;

        if ($connection === '') {
            throw new UnsupportedBatchFeatureException(
                'An explicit native OpenAI provider connection is required for request resolution.'
            );
        }

        if ($this->ai->hasFakeGatewayFor($agent)) {
            throw new UnsupportedBatchFeatureException(sprintf(
                'Agent [%s] is faked; request resolution requires Laravel AI\'s native OpenAI request builder.',
                $agent::class,
            ));
        }

        $nativeProvider = $this->resolveNativeProvider($connection);

        try {
            $resolvedModel = $this->resolveModel($agent, $nativeProvider, $model);
            $timeout = $this->resolveTimeout($agent);
            $this->assertMiddlewareAcceptsInstalledInput($agent);
        } catch (RequestResolutionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RequestResolutionException(
                'Laravel AI request configuration could not be resolved safely.'
            );
        }

        $capturingProvider = clone $nativeProvider;
        $capturingProvider->useTextGateway(new CapturingOpenAiGateway($this->events));

        try {
            $capturingProvider->prompt(new AgentPrompt(
                $agent,
                $prompt,
                $attachments,
                $capturingProvider,
                $resolvedModel,
                $timeout,
            ));
        } catch (CapturedOpenAiRequest $captured) {
            return $this->toResolvedRequest($connection, $captured->body());
        } catch (RequestResolutionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RequestResolutionException(
                'Laravel AI request resolution failed before a provider request could be captured.'
            );
        }

        throw new RequestResolutionException(
            'Agent middleware completed without producing a provider request; short-circuiting middleware cannot be resolved.'
        );
    }

    private function resolveNativeProvider(string $connection): OpenAiProvider
    {
        try {
            $provider = $this->ai->textProvider($connection);
        } catch (Throwable) {
            throw new UnsupportedBatchFeatureException(sprintf(
                'Provider connection [%s] could not be resolved as a native OpenAI provider.',
                $connection,
            ));
        }

        if ($provider::class !== OpenAiProvider::class || $provider->driver() !== Lab::OpenAI->value) {
            throw new UnsupportedBatchFeatureException(sprintf(
                'Provider connection [%s] is not Laravel AI\'s native OpenAI provider.',
                $connection,
            ));
        }

        $configuredUrl = $provider->additionalConfiguration()['url'] ?? self::OPENAI_BASE_URL;

        if (! is_string($configuredUrl) || rtrim($configuredUrl, '/') !== self::OPENAI_BASE_URL) {
            throw new UnsupportedBatchFeatureException(sprintf(
                'Provider connection [%s] uses a custom OpenAI base URL, which is unsupported.',
                $connection,
            ));
        }

        return $provider;
    }

    private function resolveModel(Agent $agent, TextProvider $provider, ?string $model): string
    {
        if ($model === null) {
            if (method_exists($agent, 'model')) {
                $model = $this->invokeAgentMethod($agent, 'model');
            } else {
                $attributes = (new ReflectionClass($agent))->getAttributes(ModelAttribute::class);
                $model = $attributes === [] ? null : $attributes[0]->newInstance()->value;
            }
        }

        if ($model === null) {
            $reflection = new ReflectionClass($agent);

            $model = match (true) {
                $reflection->getAttributes(UseSmartestModel::class) !== [] => $provider->smartestTextModel(),
                $reflection->getAttributes(UseCheapestModel::class) !== [] => $provider->cheapestTextModel(),
                default => $provider->defaultTextModel(),
            };
        }

        if (! is_string($model) || trim($model) === '') {
            throw new RequestResolutionException('Laravel AI resolved an invalid or empty model name.');
        }

        return $model;
    }

    private function resolveTimeout(Agent $agent): int
    {
        if (method_exists($agent, 'timeout')) {
            $timeout = $this->invokeAgentMethod($agent, 'timeout');
        } else {
            $attributes = (new ReflectionClass($agent))->getAttributes(TimeoutAttribute::class);
            $timeout = $attributes === [] ? 60 : $attributes[0]->newInstance()->value;
        }

        if (! is_int($timeout)) {
            throw new RequestResolutionException('Laravel AI resolved an invalid request timeout.');
        }

        return $timeout;
    }

    /**
     * Reject agent middleware written for the other Laravel AI middleware contract.
     *
     * Such middleware raises a type error inside Laravel AI's pipeline, which the
     * resolver would otherwise report only as a generic resolution failure.
     */
    private function assertMiddlewareAcceptsInstalledInput(Agent $agent): void
    {
        if (! $agent instanceof HasMiddleware) {
            return;
        }

        $input = LaravelAiVersion::middlewareInput();

        foreach ($agent->middleware() as $middleware) {
            $handler = match (true) {
                $middleware instanceof Closure => new ReflectionFunction($middleware),
                (is_object($middleware) || (is_string($middleware) && class_exists($middleware)))
                    && method_exists($middleware, 'handle') => new ReflectionMethod($middleware, 'handle'),
                default => null,
            };

            if ($handler === null || $this->acceptsInput($handler, $input)) {
                continue;
            }

            throw new RequestResolutionException(sprintf(
                'Agent middleware [%s] does not accept [%s], which the installed Laravel AI release passes to '.
                'agent middleware. Update the middleware for the installed Laravel AI release.',
                $middleware instanceof Closure ? 'Closure' : (is_object($middleware) ? $middleware::class : $middleware),
                $input,
            ));
        }
    }

    /** @param class-string $input */
    private function acceptsInput(ReflectionFunctionAbstract $handler, string $input): bool
    {
        $type = ($handler->getParameters()[0] ?? null)?->getType();

        return $type === null || $this->typeAccepts($type, $input);
    }

    /** @param class-string $input */
    private function typeAccepts(ReflectionType $type, string $input): bool
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->typeAccepts($member, $input)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (! $this->typeAccepts($member, $input)) {
                    return false;
                }
            }

            return true;
        }

        if (! $type instanceof ReflectionNamedType) {
            return true;
        }

        return $type->isBuiltin()
            ? in_array($type->getName(), ['mixed', 'object'], true)
            : is_a($input, $type->getName(), true);
    }

    private function invokeAgentMethod(Agent $agent, string $method): mixed
    {
        return (new ReflectionMethod($agent, $method))->invoke($agent);
    }

    /** @param array<string, mixed> $body */
    private function toResolvedRequest(string $connection, array $body): ResolvedProviderRequest
    {
        if (! isset($body['model']) || ! is_string($body['model']) || trim($body['model']) === '') {
            throw new RequestResolutionException(
                'Laravel AI produced an OpenAI request without a valid model.'
            );
        }

        return new ResolvedProviderRequest(
            provider: $connection,
            method: 'POST',
            endpoint: '/v1/responses',
            body: $body,
            headers: [],
        );
    }
}
