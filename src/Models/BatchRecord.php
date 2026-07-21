<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Models;

use Illuminate\Database\Eloquent\Model;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;

final class BatchRecord extends Model
{
    protected $table = 'ai_batches';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updated(function (BatchRecord $batch) {
            if ($batch->isDirty('status')) {
                event(new \RefinePhp\LaravelAiBatch\Events\BatchStatusUpdated($batch));
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'request_count' => 'integer',
            'completed_count' => 'integer',
            'failed_count' => 'integer',
            'validation_errors' => 'array',
            'submitted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
