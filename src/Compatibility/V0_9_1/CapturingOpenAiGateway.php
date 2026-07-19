<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Compatibility\V0_9_1;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use LogicException;

/**
 * Laravel AI v0.9.1 compatibility gateway.
 *
 * @internal
 */
final class CapturingOpenAiGateway extends OpenAiGateway
{
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        if ($stepContext->stepNumber !== 0 || $stepContext->continuationToken !== null) {
            throw new LogicException('Only the initial Laravel AI text generation step can be captured.');
        }

        $body = $this->buildStepBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $stepContext,
        );

        throw new CapturedOpenAiRequest($body);
    }
}
