<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use RefinePhp\LaravelAiBatch\Compatibility\V0_9_1\LaravelAiRequestResolver;
use RefinePhp\LaravelAiBatch\Console\Commands\BatchStatusCommand;
use RefinePhp\LaravelAiBatch\Console\Commands\CancelBatchCommand;
use RefinePhp\LaravelAiBatch\Console\Commands\PollBatchesCommand;
use RefinePhp\LaravelAiBatch\Contracts\BatchProvider;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Contracts\RequestResolver;
use RefinePhp\LaravelAiBatch\Exceptions\BatchConfigurationException;
use RefinePhp\LaravelAiBatch\Polling\BatchCanceller;
use RefinePhp\LaravelAiBatch\Polling\BatchLock;
use RefinePhp\LaravelAiBatch\Polling\BatchPoller;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchClient;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchProvider;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlParser;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiJsonlWriter;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiStatusMapper;

final class LaravelAiBatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-batch.php', 'ai-batch');

        $this->app->singleton(RequestResolver::class, LaravelAiRequestResolver::class);

        $this->registerOpenAiProvider();

        $this->app->singleton(BatchLock::class, function (Container $app): BatchLock {
            $store = $app['config']->get('ai-batch.polling.lock_store');

            return new BatchLock(
                cache: $app->make(CacheFactory::class),
                store: is_string($store) && $store !== '' ? $store : null,
                seconds: (int) $app['config']->get('ai-batch.polling.lock_seconds', 120),
            );
        });

        $this->app->singleton(BatchPoller::class, fn (Container $app): BatchPoller => new BatchPoller(
            repository: $app->make(BatchRepository::class),
            manager: $app->make(BatchManager::class),
            lock: $app->make(BatchLock::class),
        ));

        $this->app->singleton(BatchCanceller::class, fn (Container $app): BatchCanceller => new BatchCanceller(
            repository: $app->make(BatchRepository::class),
            manager: $app->make(BatchManager::class),
            lock: $app->make(BatchLock::class),
        ));

        $this->app->singleton(BatchRepository::class, function (Container $app): BatchRepository {
            $driver = (string) $app['config']->get('ai-batch.repository', 'eloquent');
            $implementation = $app['config']->get("ai-batch.repositories.{$driver}");

            if (! is_string($implementation) || $implementation === '') {
                throw new BatchConfigurationException("Batch repository [{$driver}] is not configured.");
            }

            $repository = $app->make($implementation);

            if (! $repository instanceof BatchRepository) {
                throw new BatchConfigurationException("Batch repository [{$implementation}] must implement BatchRepository.");
            }

            return $repository;
        });

        $this->app->singleton(BatchManager::class, function (Container $app): BatchManager {
            $providers = $app['config']->get('ai-batch.providers', []);

            if (! is_array($providers)) {
                throw new BatchConfigurationException('The ai-batch.providers configuration value must be an array.');
            }

            /** @var array<string, class-string<BatchProvider>> $providers */
            return new BatchManager(
                container: $app,
                resolver: $app->make(RequestResolver::class),
                repository: $app->make(BatchRepository::class),
                providers: $providers,
            );
        });

        $this->app->alias(BatchManager::class, 'ai-batch');
    }

    private function registerOpenAiProvider(): void
    {
        $this->app->singleton(OpenAiBatchClient::class, function (Container $app): OpenAiBatchClient {
            $connection = (string) $app['config']->get('ai-batch.openai.connection', 'openai');
            $provider = $app['config']->get("ai.providers.{$connection}", []);
            $timeouts = $app['config']->get('ai-batch.openai', []);

            if (! is_array($provider) || ($provider['driver'] ?? null) !== 'openai') {
                throw new BatchConfigurationException(
                    "Laravel AI provider connection [{$connection}] must use the native OpenAI driver.",
                );
            }

            $baseUrl = $provider['url'] ?? 'https://api.openai.com/v1';

            if (! is_string($baseUrl) || rtrim($baseUrl, '/') !== 'https://api.openai.com/v1') {
                throw new BatchConfigurationException(
                    "Laravel AI provider connection [{$connection}] must use the official OpenAI API base URL.",
                );
            }

            if (! is_array($timeouts)) {
                $timeouts = [];
            }

            return new OpenAiBatchClient($app->make(Factory::class), [
                'api_key' => $provider['key'] ?? null,
                'base_url' => $baseUrl,
                'organization' => $provider['organization'] ?? null,
                'project' => $provider['project'] ?? null,
                'connect_timeout' => $timeouts['connect_timeout'] ?? 10,
                'upload_timeout' => $timeouts['upload_timeout'] ?? 120,
                'request_timeout' => $timeouts['request_timeout'] ?? 30,
                'download_timeout' => $timeouts['download_timeout'] ?? 120,
            ]);
        });

        $this->app->singleton(OpenAiJsonlWriter::class, function (Container $app): OpenAiJsonlWriter {
            return new OpenAiJsonlWriter(
                maxRequests: (int) $app['config']->get('ai-batch.openai.max_requests', 50_000),
                maxBytes: (int) $app['config']->get('ai-batch.openai.max_bytes', 200 * 1024 * 1024),
            );
        });

        $this->app->singleton(OpenAiBatchProvider::class, function (Container $app): OpenAiBatchProvider {
            return new OpenAiBatchProvider(
                client: $app->make(OpenAiBatchClient::class),
                writer: $app->make(OpenAiJsonlWriter::class),
                statusMapper: $app->make(OpenAiStatusMapper::class),
                parser: $app->make(OpenAiJsonlParser::class),
                connection: (string) $app['config']->get('ai-batch.openai.connection', 'openai'),
            );
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            BatchStatusCommand::class,
            CancelBatchCommand::class,
            PollBatchesCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/ai-batch.php' => config_path('ai-batch.php'),
        ], ['ai-batch', 'ai-batch-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['ai-batch', 'ai-batch-migrations']);
    }
}
