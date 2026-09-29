<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Tests\Fixtures\Middleware;

use Closure;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;

/**
 * The Laravel AI 1.x counterpart of {@see RevisePrompt}.
 */
final class ReviseStep
{
    public function handle(PendingStep $step, Closure $next): mixed
    {
        $messages = $step->messages;
        $prompt = array_pop($messages);

        if ($prompt instanceof UserMessage) {
            $prompt = new UserMessage($prompt->content.PHP_EOL.PHP_EOL.'Added by middleware.', $prompt->attachments);
        }

        return $next($step->withMessages([...$messages, $prompt]));
    }
}
