<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Contracts;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use RefinePhp\LaravelAiBatch\Data\ResolvedProviderRequest;

interface RequestResolver
{
    /** @param array<int, mixed> $attachments */
    public function resolve(
        Agent $agent,
        string $prompt,
        Lab|string $provider,
        array $attachments = [],
        ?string $model = null,
    ): ResolvedProviderRequest;
}
