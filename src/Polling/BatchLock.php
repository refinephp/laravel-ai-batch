<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Polling;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Exceptions\BatchConfigurationException;

final class BatchLock
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ?string $store = null,
        private readonly int $seconds = 120,
    ) {
        if ($this->seconds < 1) {
            throw new BatchConfigurationException('The batch lock duration must be at least one second.');
        }
    }

    /** @param Closure(): ProviderBatch $callback */
    public function run(string $batchId, Closure $callback): ?ProviderBatch
    {
        $store = $this->cache->store($this->store)->getStore();

        if (! $store instanceof LockProvider) {
            throw new BatchConfigurationException('The configured cache store does not support atomic locks.');
        }

        $result = $store->lock($this->name($batchId), $this->seconds)->get($callback);

        return $result instanceof ProviderBatch ? $result : null;
    }

    private function name(string $batchId): string
    {
        return 'ai-batch:lifecycle:'.hash('sha256', $batchId);
    }
}
