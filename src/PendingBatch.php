<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use RefinePhp\LaravelAiBatch\Contracts\BatchProvider;
use RefinePhp\LaravelAiBatch\Contracts\BatchRepository;
use RefinePhp\LaravelAiBatch\Contracts\RequestResolver;
use RefinePhp\LaravelAiBatch\Data\BatchRequest;
use RefinePhp\LaravelAiBatch\Data\BatchSubmission;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Data\ResolvedProviderRequest;
use RefinePhp\LaravelAiBatch\Exceptions\BatchAlreadySubmittedException;
use RefinePhp\LaravelAiBatch\Exceptions\BatchPersistenceException;
use RefinePhp\LaravelAiBatch\Exceptions\DuplicateCustomIdException;
use RefinePhp\LaravelAiBatch\Exceptions\InvalidBatchRequestException;
use RefinePhp\LaravelAiBatch\Exceptions\InvalidCustomIdException;
use RefinePhp\LaravelAiBatch\Exceptions\ProviderMismatchException;
use Throwable;

final class PendingBatch
{
    private ?string $name = null;

    /** @var Agent|class-string<Agent>|null */
    private Agent|string|null $agent = null;

    /** @var array<string, mixed> */
    private array $agentArguments = [];

    /**
     * @var list<BatchRequest|array{custom_id: string, prompt: string, attachments: array<int, mixed>, model: ?string}>
     */
    private array $items = [];

    /** @var array<string, true> */
    private array $customIds = [];

    private bool $submitted = false;

    public function __construct(
        private readonly string $provider,
        private readonly RequestResolver $resolver,
        private readonly BatchProvider $batchProvider,
        private readonly BatchRepository $repository,
        private readonly string $completionWindow = '24h',
    ) {}

    public function name(string $name): self
    {
        if ($name === '') {
            throw new InvalidBatchRequestException('A batch name must not be empty.');
        }

        $this->name = $name;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function agent(Agent|string $agent, array $arguments = []): self
    {
        if (is_string($agent) && ! is_a($agent, Agent::class, true)) {
            throw new InvalidBatchRequestException("Agent [{$agent}] must implement Laravel AI's Agent contract.");
        }

        $this->agent = $agent;
        $this->agentArguments = $arguments;

        return $this;
    }

    /** @param array<int, mixed> $attachments */
    public function add(
        string $customId,
        string $prompt,
        array $attachments = [],
        ?string $model = null,
    ): self {
        if ($this->agent === null) {
            throw new InvalidBatchRequestException('Call agent() before adding unresolved batch requests.');
        }

        $this->guardCustomId($customId);
        $this->items[] = [
            'custom_id' => $customId,
            'prompt' => $prompt,
            'attachments' => $attachments,
            'model' => $model,
        ];

        return $this;
    }

    public function addRequest(string $customId, ResolvedProviderRequest $request): self
    {
        $this->guardCustomId($customId);

        if ($request->provider() !== $this->provider) {
            throw new ProviderMismatchException(
                "Request provider [{$request->provider()}] does not match batch provider [{$this->provider}].",
            );
        }

        $this->items[] = new BatchRequest($customId, $request);

        return $this;
    }

    public function submit(): ProviderBatch
    {
        if ($this->submitted) {
            throw new BatchAlreadySubmittedException('This pending batch has already been submitted.');
        }

        $this->submitted = true;
        $requests = array_map(fn (BatchRequest|array $item): BatchRequest => $this->resolveItem($item), $this->items);
        $submission = new BatchSubmission(
            id: (string) Str::uuid(),
            provider: $this->provider,
            name: $this->name,
            completionWindow: $this->completionWindow,
            requests: $requests,
        );
        $batch = $this->batchProvider->submit($submission);

        try {
            $this->repository->save($batch);
        } catch (Throwable $exception) {
            if ($exception instanceof BatchPersistenceException) {
                throw $exception;
            }

            throw new BatchPersistenceException(
                "Remote batch [{$batch->providerBatchId()}] was created but could not be persisted.",
                previous: $exception,
            );
        }

        return $batch;
    }

    /**
     * @param  BatchRequest|array{custom_id: string, prompt: string, attachments: array<int, mixed>, model: ?string}  $item
     */
    private function resolveItem(BatchRequest|array $item): BatchRequest
    {
        if ($item instanceof BatchRequest) {
            return $item;
        }

        $agent = $this->makeAgent();

        return new BatchRequest(
            $item['custom_id'],
            $this->resolver->resolve(
                agent: $agent,
                prompt: $item['prompt'],
                provider: $this->provider,
                attachments: $item['attachments'],
                model: $item['model'],
            ),
        );
    }

    private function makeAgent(): Agent
    {
        if ($this->agent instanceof Agent) {
            return $this->agent;
        }

        if (! is_string($this->agent)) {
            throw new InvalidBatchRequestException('No Laravel AI agent has been configured.');
        }

        $class = $this->agent;

        if (! method_exists($class, 'make')) {
            throw new InvalidBatchRequestException("Agent [{$class}] must provide the static make() factory.");
        }

        /** @var mixed $instance */
        $instance = $class::make(...$this->agentArguments);

        if (! $instance instanceof Agent) {
            throw new InvalidBatchRequestException("Agent factory [{$class}::make] did not return an Agent.");
        }

        return $instance;
    }

    private function guardCustomId(string $customId): void
    {
        if ($customId === '') {
            throw new InvalidCustomIdException('A batch request custom ID must not be empty.');
        }

        if (isset($this->customIds[$customId])) {
            throw new DuplicateCustomIdException("Duplicate batch custom ID [{$customId}].");
        }

        $this->customIds[$customId] = true;
    }
}
