<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('provider_batch_id');
            $table->string('name')->nullable();
            $table->string('status');
            $table->string('provider_status');
            $table->string('input_file_id')->nullable();
            $table->string('output_file_id')->nullable();
            $table->string('error_file_id')->nullable();
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('validation_errors')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_batch_id']);
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_batches');
    }
};
