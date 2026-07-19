<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch;

use Illuminate\Container\Container;
use Illuminate\Support\LazyCollection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use RefinePhp\LaravelAiBatch\Contracts\BatchProvider;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Contracts\RequestResolver;
use RefinePhp\LaravelAiBatch\Data\BatchError;
use RefinePhp\LaravelAiBatch\Data\BatchResult;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Data\ResolvedProviderRequest;
use RefinePhp\LaravelAiBatch\Exceptions\BatchConfigurationException;
use RefinePhp\LaravelAiBatch\Exceptions\BatchNotFoundException;

final class BatchManager
{
    /** @param array<string, class-string<BatchProvider>> $providers */
    public function __construct(
        private readonly Container $container,
        private readonly RequestResolver $resolver,
        private readonly BatchRepository $repository,
        private readonly array $providers = [],
    ) {}

    public function forProvider(Lab|string $provider): PendingBatch
    {
        $name = $this->providerName($provider);

        return new PendingBatch(
            provider: $name,
            resolver: $this->resolver,
            batchProvider: $this->batchProvider($name),
            repository: $this->repository,
            completionWindow: (string) config('ai-batch.completion_window', '24h'),
        );
    }

    /** @param array<int, mixed> $attachments */
    public function resolve(
        Agent $agent,
        string $prompt,
        Lab|string $provider,
        array $attachments = [],
        ?string $model = null,
    ): ResolvedProviderRequest {
        return $this->resolver->resolve($agent, $prompt, $provider, $attachments, $model);
    }

    public function find(string $id): ?ProviderBatch
    {
        return $this->repository->find($id);
    }

    public function findOrFail(string $id): ProviderBatch
    {
        return $this->find($id) ?? throw new BatchNotFoundException("Batch [{$id}] was not found.");
    }

    public function refresh(ProviderBatch $batch): ProviderBatch
    {
        $snapshot = $this->batchProvider($batch->provider())->refresh($batch);
        $this->repository->save($snapshot);

        return $snapshot;
    }

    public function cancel(ProviderBatch $batch): ProviderBatch
    {
        $snapshot = $this->batchProvider($batch->provider())->cancel($batch);
        $this->repository->save($snapshot);

        return $snapshot;
    }

    /** @return LazyCollection<int, BatchResult> */
    public function results(ProviderBatch $batch): LazyCollection
    {
        return LazyCollection::make(function () use ($batch) {
            yield from $this->batchProvider($batch->provider())->results($batch);
        });
    }

    /** @return LazyCollection<int, BatchError> */
    public function errors(ProviderBatch $batch): LazyCollection
    {
        return LazyCollection::make(function () use ($batch) {
            yield from $batch->validationErrors();
            yield from $this->batchProvider($batch->provider())->errors($batch);
        });
    }

    private function providerName(Lab|string $provider): string
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;

        if ($name === '') {
            throw new BatchConfigurationException('A batch provider name must not be empty.');
        }

        return $name;
    }

    private function batchProvider(string $connection): BatchProvider
    {
        $driver = config("ai.providers.{$connection}.driver", $connection);
        $implementation = $this->providers[$connection] ?? $this->providers[$driver] ?? null;

        if (! is_string($implementation)) {
            throw new BatchConfigurationException("No batch provider is configured for [{$connection}].");
        }

        $provider = $this->container->make($implementation);

        if (! $provider instanceof BatchProvider) {
            throw new BatchConfigurationException("Batch provider [{$implementation}] must implement BatchProvider.");
        }

        if ($provider->name() !== $connection) {
            throw new BatchConfigurationException(
                "Batch provider [{$implementation}] is configured for [{$provider->name()}], not [{$connection}].",
            );
        }

        return $provider;
    }
}
