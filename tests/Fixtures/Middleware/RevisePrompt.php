<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Tests\Fixtures\Middleware;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

final class RevisePrompt
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        return $next($prompt->append('Added by middleware.'));
    }
}
