<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use RefinePhp\LaravelAiBatch\Tests\Fixtures\Tools\LookupFact;

#[MaxTokens(321)]
#[Temperature(0.25)]
#[TopP(0.8)]
#[Strict]
final class ParityAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasStructuredOutput, HasTools
{
    use Promptable;

    /** @param array<int, object> $middleware */
    public function __construct(
        private readonly array $middleware = [],
        private readonly string $selectedModel = 'gpt-5.4-mini',
        private readonly ?string $bodyModelOverride = null,
    ) {}

    public function instructions(): string
    {
        return 'Return a concise fact summary.';
    }

    public function model(): string
    {
        return $this->selectedModel;
    }

    public function messages(): iterable
    {
        return [
            new Message('user', 'Earlier question.'),
            new Message('assistant', 'Earlier answer.'),
        ];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return $this->middleware;
    }

    public function providerOptions(Lab|string $provider): array
    {
        $options = [
            'metadata' => ['source' => 'request-resolution-test'],
            'reasoning' => ['effort' => 'low'],
        ];

        if ($this->bodyModelOverride !== null) {
            $options['model'] = $this->bodyModelOverride;
        }

        return $options;
    }

    public function tools(): iterable
    {
        return [new LookupFact];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
        ];
    }
}
