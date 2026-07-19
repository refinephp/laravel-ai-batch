<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Tests\Fixtures\Middleware;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

final class ShortCircuit
{
    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        return new AgentResponse(
            'short-circuit-invocation',
            'No provider request is needed.',
            new Usage,
            new Meta,
        );
    }
}
