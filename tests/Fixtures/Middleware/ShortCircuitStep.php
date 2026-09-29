<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Tests\Fixtures\Middleware;

use Closure;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * The Laravel AI 1.x counterpart of {@see ShortCircuit}.
 */
final class ShortCircuitStep
{
    public function handle(PendingStep $step, Closure $next): StepResponse
    {
        return new StepResponse(
            'No provider request is needed.',
            [],
            FinishReason::Stop,
            new TextUsage,
            new Meta,
        );
    }
}
