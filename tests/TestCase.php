<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RefinePhp\LaravelAiBatch\LaravelAiBatchServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            LaravelAiBatchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('ai.providers.openai', [
            'driver' => 'openai',
            'key' => 'test-key',
            'url' => 'https://api.openai.com/v1',
            'store' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_07_19_000001_create_ai_batches_table.php';
        $migration->up();
    }
}
