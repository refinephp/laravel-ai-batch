<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiBatchProvider;
use RefinePhp\LaravelAiBatch\Repositories\EloquentBatchRepository;
use RefinePhp\LaravelAiBatch\Repositories\NullBatchRepository;

return [
    'repository' => Env::get('AI_BATCH_REPOSITORY', 'eloquent'),

    'repositories' => [
        'eloquent' => EloquentBatchRepository::class,
        'null' => NullBatchRepository::class,
    ],

    'providers' => [
        'openai' => OpenAiBatchProvider::class,
    ],

    'completion_window' => '24h',

    'polling' => [
        'lock_store' => Env::get('AI_BATCH_LOCK_STORE'),
        'lock_seconds' => 120,
    ],

    'openai' => [
        'connection' => Env::get('AI_BATCH_OPENAI_CONNECTION', 'openai'),
        'connect_timeout' => 10,
        'upload_timeout' => 120,
        'request_timeout' => 30,
        'download_timeout' => 120,
        'max_requests' => 50_000,
        'max_bytes' => 200 * 1024 * 1024,
    ],
];
