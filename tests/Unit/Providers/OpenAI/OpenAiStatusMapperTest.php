<?php

declare(strict_types=1);

use RefinePhp\LaravelAiBatch\Enums\BatchStatus;
use RefinePhp\LaravelAiBatch\Providers\OpenAI\OpenAiStatusMapper;

it('maps OpenAI lifecycle snapshots counts timestamps and validation errors', function () {
    $batch = (new OpenAiStatusMapper)->map([
        'id' => 'batch_123',
        'status' => 'failed',
        'input_file_id' => 'file-input',
        'error_file_id' => 'file-error',
        'created_at' => 1_784_476_800,
        'failed_at' => 1_784_476_900,
        'expires_at' => 1_784_563_200,
        'request_counts' => ['total' => 2, 'completed' => 0, 'failed' => 2],
        'errors' => ['data' => [[
            'code' => 'invalid_request',
            'line' => 2,
            'message' => "Invalid\nmodel.",
            'param' => 'body.model',
        ]]],
    ], 'local-123', 'openai', 'Test batch');

    expect($batch->status())->toBe(BatchStatus::Failed)
        ->and($batch->providerStatus())->toBe('failed')
        ->and($batch->requestCount())->toBe(2)
        ->and($batch->failedCount())->toBe(2)
        ->and($batch->failedAt()?->timestamp)->toBe(1_784_476_900)
        ->and($batch->validationErrors())->toHaveCount(1)
        ->and($batch->validationErrors()[0]->line())->toBe(2)
        ->and($batch->validationErrors()[0]->message())->toBe('Invalid model.');
});

it('preserves an unknown raw status and uses the local request count fallback', function () {
    $batch = (new OpenAiStatusMapper)->map([
        'id' => 'batch_new',
        'status' => 'new_provider_status',
    ], 'local-new', 'openai', null, requestCount: 7);

    expect($batch->status())->toBe(BatchStatus::Unknown)
        ->and($batch->providerStatus())->toBe('new_provider_status')
        ->and($batch->requestCount())->toBe(7)
        ->and($batch->isTerminal())->toBeFalse();
});
