<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RefinePhp\LaravelAiBatch\BatchManager;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Contracts\RequestResolver;
use RefinePhp\LaravelAiBatch\Facades\AiBatch;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchProvider;
use RefinePhp\LaravelAiBatch\Repositories\EloquentBatchRepository;

it('registers the manager resolver repository provider and facade', function (): void {
    expect(app(BatchManager::class))->toBeInstanceOf(BatchManager::class)
        ->and(app(RequestResolver::class))->toBeInstanceOf(RequestResolver::class)
        ->and(app(BatchRepository::class))->toBeInstanceOf(EloquentBatchRepository::class)
        ->and(app(OpenAiBatchProvider::class))->toBeInstanceOf(OpenAiBatchProvider::class)
        ->and(AiBatch::getFacadeRoot())->toBeInstanceOf(BatchManager::class)
        ->and(Schema::hasTable('ai_batches'))->toBeTrue();
});
