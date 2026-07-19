# Public API and Domain Model Proposal

Status: proposed for lead review. This document is intentionally limited to the public API and domain model. It does not select or implement the Laravel AI compatibility adapter or the OpenAI HTTP client.

## Design goals

- Reuse a real `Laravel\Ai\Contracts\Agent` for both synchronous and batch requests.
- Match Laravel AI's current invocation semantics instead of introducing a second "context" convention.
- Keep OpenAI transport details behind one small provider contract.
- Preserve caller-provided custom IDs exactly so results can be correlated without a mapping table.
- Keep request, batch, result, and error data immutable.
- Put side effects on `BatchManager` / `BatchProvider`, not on data objects or static global state.
- Fail specifically when exact request resolution or a requested feature is unsupported. Never silently omit request features.
- Make the facade and dependency-injected manager expose the same API.

## Research basis for `add()`

As of Laravel AI `main` at commit [`a1b3ce7`](https://github.com/laravel/ai/tree/a1b3ce7437adb8bda22eb4e0308a376cd64da3d9), the public `Agent::prompt()` contract is:

```php
public function prompt(
    Decisions|string $prompt,
    array $attachments = [],
    Lab|array|string|null $provider = null,
    ?string $model = null,
    ?int $timeout = null,
): AgentResponse;
```

The [current Laravel AI documentation](https://laravel.com/docs/13.x/ai-sdk#prompting) creates an agent through standard construction or `AgentClass::make(...)`, then passes a string prompt and attachments to `prompt()`. `make(...)` is where constructor arguments and dependency injection belong. There is no public `with(array $context)` agent invocation API.

Therefore the primary batch API should **not** be `add(string $customId, array $context)`. That shape would invent a second input model and could not reliably decide whether the array represents constructor arguments, prompt interpolation data, messages, or provider options.

The proposed fluent equivalent is:

```php
AiBatch::forProvider(Lab::OpenAI)
    ->agent(GeneratePullRequestSummary::class)
    ->add('pr-123', prompt: $firstPrompt)
    ->add('pr-124', prompt: $secondPrompt);
```

`add()` mirrors the request-body-affecting portion of `prompt()`: prompt, attachments, and optional model. Provider is fixed by `forProvider()`. HTTP timeout is intentionally not an item argument because it controls the synchronous HTTP call, not the provider request body. Batch upload, polling, and download timeouts belong in package configuration.

When an agent needs per-item constructor state, the caller should create the real agent instance and use the explicit resolver / resolved-request API:

```php
$first = AiBatch::resolve(
    agent: GeneratePullRequestSummary::make(pullRequest: $firstPullRequest),
    prompt: 'Generate the pull request summary.',
    provider: Lab::OpenAI,
);

$second = AiBatch::resolve(
    agent: GeneratePullRequestSummary::make(pullRequest: $secondPullRequest),
    prompt: 'Generate the pull request summary.',
    provider: Lab::OpenAI,
);

$batch = AiBatch::forProvider(Lab::OpenAI)
    ->addRequest('pr-123', $first)
    ->addRequest('pr-124', $second)
    ->submit();
```

This is verbose only for the less common case, and it remains exact. Do not overload `add()` with both strings and arbitrary arrays.

## Proposed public usage

### Facade

```php
use App\Ai\Agents\GeneratePullRequestSummary;
use Laravel\Ai\Enums\Lab;
use RefinePhp\LaravelAiBatch\Facades\AiBatch;

$batch = AiBatch::forProvider(Lab::OpenAI)
    ->name('pull-request-summaries')
    ->agent(GeneratePullRequestSummary::class)
    ->add('pr-123', prompt: $firstPrompt)
    ->add('pr-124', prompt: $secondPrompt)
    ->submit();

$batch->id();              // package UUID, stable across persistence
$batch->providerBatchId(); // OpenAI batch ID
$batch->status();
```

Attachments and an explicit model follow Laravel AI's terminology:

```php
$batch = AiBatch::forProvider(Lab::OpenAI)
    ->agent(DocumentAnalyzer::class)
    ->add(
        'document-42',
        prompt: 'Extract the invoice fields.',
        attachments: [$document],
        model: 'gpt-5-mini',
    )
    ->submit();
```

Lifecycle operations return new immutable snapshots:

```php
$batch = AiBatch::findOrFail($batchId);
$batch = AiBatch::refresh($batch);

if ($batch->isCompleted()) {
    foreach (AiBatch::results($batch) as $result) {
        if ($result->successful()) {
            process($result->customId(), $result->response());
        } else {
            report($result->error());
        }
    }
}

$batch = AiBatch::cancel($batch);
```

The reassignment is deliberate: `ProviderBatch` is a snapshot, so `refresh()` and `cancel()` do not mutate an object that may also be held by another service or queued job.

### Dependency injection

```php
use RefinePhp\LaravelAiBatch\BatchManager;

final class SubmitSummaryBatch
{
    public function __construct(private BatchManager $batches) {}

    public function handle(): ProviderBatch
    {
        return $this->batches
            ->forProvider(Lab::OpenAI)
            ->agent(GeneratePullRequestSummary::class)
            ->add('pr-123', prompt: $this->promptFor(123))
            ->submit();
    }
}
```

`AiBatch` is a conventional facade for the container-bound `BatchManager`. Neither the manager nor domain objects use the facade internally.

### Lower-level request resolution

```php
$request = AiBatch::resolve(
    agent: GeneratePullRequestSummary::make(),
    prompt: $prompt,
    attachments: [],
    provider: Lab::OpenAI,
    model: 'gpt-5-mini',
);

$request->provider(); // 'openai'
$request->method();   // 'POST'
$request->endpoint(); // '/v1/responses'
$request->body();     // exact provider body
$request->headers();  // safe, non-secret request headers only
```

`ResolvedProviderRequest` must never contain authorization, cookies, API keys, or another credential-bearing header. If exact execution depends on a per-request secret or unsupported header, resolution throws `UnsupportedBatchFeatureException`; it does not return a subtly incomplete request.

## Public class surface

### `BatchManager`

The manager is the application-facing service and coordinator.

```php
final class BatchManager
{
    public function forProvider(Lab|string $provider): PendingBatch;

    public function resolve(
        Agent $agent,
        string $prompt,
        Lab|string $provider,
        array $attachments = [],
        ?string $model = null,
    ): ResolvedProviderRequest;

    public function find(string $id): ?ProviderBatch;

    public function findOrFail(string $id): ProviderBatch;

    public function refresh(ProviderBatch $batch): ProviderBatch;

    public function cancel(ProviderBatch $batch): ProviderBatch;

    /** @return LazyCollection<int, BatchResult> */
    public function results(ProviderBatch $batch): LazyCollection;

    /** @return LazyCollection<int, BatchError> */
    public function errors(ProviderBatch $batch): LazyCollection;
}
```

The manager resolves configured driver names and persists new snapshots returned by a provider. A batch's recorded provider is always used for later operations; callers cannot accidentally refresh an OpenAI batch through another driver.

### `PendingBatch`

`PendingBatch` is a Laravel-style mutable fluent builder. It is the intentional exception to immutable domain data.

```php
final class PendingBatch
{
    public function name(string $name): self;

    /**
     * @param Agent|class-string<Agent> $agent
     * @param array<string, mixed> $arguments Named arguments passed to Agent::make().
     */
    public function agent(Agent|string $agent, array $arguments = []): self;

    /** @param array<int, mixed> $attachments */
    public function add(
        string $customId,
        string $prompt,
        array $attachments = [],
        ?string $model = null,
    ): self;

    public function addRequest(
        string $customId,
        ResolvedProviderRequest $request,
    ): self;

    public function submit(): ProviderBatch;
}
```

Rules:

- `agent()` is required before `add()`, but not before `addRequest()`.
- A class-string agent is resolved through `Agent::make(...$arguments)` for each item so per-item resolution cannot leak mutable state between requests. Passing an instance explicitly opts into reusing that instance.
- `add()` resolves to a `BatchRequest` without sending a provider request. Whether resolution is eager at `add()` or deferred until `submit()` is an implementation detail, but all resolution and validation errors must occur before the input file is uploaded.
- At least one item is required.
- Custom IDs must be non-empty and unique within the batch. Additional provider-specific length/character rules are enforced only when documented; the current OpenAI documentation publishes no maximum, so version 1 must not invent one.
- IDs are never trimmed, slugged, truncated, hashed, or otherwise normalized. Invalid IDs throw; valid IDs round-trip byte-for-byte.
- Every resolved request must target the selected provider. OpenAI-specific `POST` enforcement, supported-endpoint checks, and same-endpoint / same-model validation belong to `OpenAiBatchProvider`.
- `submit()` may only be called once for a builder instance; repeat calls throw `BatchAlreadySubmittedException` rather than risking duplicate remote batches.

### `ResolvedProviderRequest`

```php
final readonly class ResolvedProviderRequest
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers Safe, non-secret headers only.
     */
    public function __construct(
        private string $provider,
        private string $method,
        private string $endpoint,
        private array $body,
        private array $headers = [],
    ) {}

    public function provider(): string;
    public function method(): string;
    public function endpoint(): string;

    /** @return array<string, mixed> */
    public function body(): array;

    /** @return array<string, string> */
    public function headers(): array;
}
```

This object contains provider-ready request data, not Laravel AI internal gateway objects. It should not implement `JsonSerializable`; provider writers explicitly choose which fields are written. The OpenAI writer writes `custom_id`, `method`, `url`, and `body`, never `headers`.

### `BatchRequest`

```php
final readonly class BatchRequest
{
    public function __construct(
        private string $customId,
        private ResolvedProviderRequest $request,
    ) {}

    public function customId(): string;
    public function request(): ResolvedProviderRequest;
}
```

`BatchRequest` is the immutable correlation boundary used by `PendingBatch` and providers. Custom ID validation is performed before construction or in a named factory so invalid objects cannot enter a submission.

### `ProviderBatch`

`ProviderBatch` is an immutable local snapshot of the remote lifecycle.

```php
final readonly class ProviderBatch
{
    public function id(): string;                 // local UUID
    public function provider(): string;           // configured driver name
    public function providerBatchId(): string;    // remote ID
    public function name(): ?string;
    public function status(): BatchStatus;
    public function providerStatus(): string;     // raw status for diagnostics
    public function inputFileId(): ?string;
    public function outputFileId(): ?string;
    public function errorFileId(): ?string;
    public function requestCount(): int;
    public function completedCount(): int;
    public function failedCount(): int;
    /** @return array<int, BatchError> */
    public function validationErrors(): array;
    public function submittedAt(): ?CarbonImmutable;
    public function completedAt(): ?CarbonImmutable;
    public function expiresAt(): ?CarbonImmutable;
    public function isCompleted(): bool;
    public function isTerminal(): bool;
    public function canBeCancelled(): bool;
}
```

The local UUID and remote ID are deliberately distinct. `id()` is safe for application routes and repository lookup. Provider API calls always use `providerBatchId()`.

Do not put `refresh()`, `cancel()`, `results()`, or `errors()` on this DTO. Those operations require provider and repository collaborators and would either make the object stateful or hide a service locator. Manager/facade methods keep the dependency boundary visible.

### `BatchResult` and `BatchError`

Provider output may contain successful and failed item outcomes out of order. Item outcomes always require a custom ID; batch-level validation errors may identify only an input line.

```php
final readonly class BatchResult
{
    /** @param array<string, mixed>|null $response */
    public function __construct(
        private string $customId,
        private ?string $providerRequestId,
        private ?int $statusCode,
        private ?array $response,
        private ?BatchError $error,
    ) {}

    public function customId(): string;
    public function providerRequestId(): ?string;
    public function statusCode(): ?int;
    public function successful(): bool;

    /** @return array<string, mixed>|null */
    public function response(): ?array;

    public function error(): ?BatchError;
}

final readonly class BatchError
{
    public function __construct(
        private ?string $customId,
        private ?string $code,
        private string $message,
        private ?string $parameter,
        private ?int $line,
        private ?int $statusCode,
        private ?string $providerRequestId,
    ) {}

    public function customId(): ?string;
    public function code(): ?string;
    public function message(): string;
    public function parameter(): ?string;
    public function line(): ?int;
    public function statusCode(): ?int;
    public function providerRequestId(): ?string;
}
```

`BatchResult` enforces exactly one of `response` or `error`. The DTOs expose parsed provider data in memory but are not persisted or logged by default. Results are lazy to avoid loading large output files into memory.

`results()` reads both available provider files and yields every item outcome, so the objective's `successful()`, `response()`, and `error()` API remains useful without requiring callers to join collections. It does not promise input order. `errors()` is a convenience stream of failures from the provider error file, prefixed by batch-level validation errors already present on the snapshot. Callers processing every item should consume `results()` once rather than call both methods.

OpenAI batch-level validation errors may identify only a JSONL line and parameter, so `BatchError` permits a null custom ID and exposes `line()`. Those errors remain available through `ProviderBatch::validationErrors()` even when no error file exists.

### `BatchStatus`

```php
enum BatchStatus: string
{
    case Validating = 'validating';
    case InProgress = 'in_progress';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function isTerminal(): bool;
    public function isSuccessful(): bool;
    public function canBeCancelled(): bool;
}
```

For version 1, these are the states required by the studied OpenAI lifecycle. `Unknown` is a forward-compatibility safety valve, not a generic provider-state model; `ProviderBatch::providerStatus()` preserves the new raw value. Unknown states are non-terminal and must not be automatically polled aggressively.

Do not add local builder states such as `draft` or `submitted` to this enum. `PendingBatch` represents a draft, while `ProviderBatch` represents a submitted remote batch.

## Contracts

### `RequestResolver`

```php
namespace RefinePhp\LaravelAiBatch\Contracts;

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
```

This is the narrow compatibility seam around Laravel AI. The public contract uses only Laravel AI's public `Agent` and `Lab` types plus this package's DTO. Internal Laravel AI gateways, prompts, mappers, and HTTP clients must not appear in the return type or package-wide public API.

Resolution guarantees:

- It makes no provider HTTP request.
- It resolves one explicit provider; failover arrays are not valid for a provider-native batch.
- It preserves instructions, conversation messages, the new user prompt, attachments, model selection, provider options, tools, tool choice, and structured schema when supported by the exact adapter.
- It returns the endpoint selected by Laravel AI, rather than allowing a second endpoint setting on `PendingBatch`.
- It throws when a feature cannot be represented exactly.

Compatibility caveats that must remain explicit:

- Current Laravel AI has no stable public `toProviderRequest()` extension point. The package implementation must be isolated and version-pinned until an upstream API exists.
- Laravel AI's synchronous provider path runs a multi-step local tool loop. A single provider batch item represents one HTTP request. Version 1 must reject agents requiring locally executed tools, approvals, sub-agents, or MCP client tools unless the package implements their continuation lifecycle. Provider-hosted tools may be allowed after provider-specific verification.
- Existing conversation messages can be resolved from an agent instance, but Laravel AI's conversation middleware persists the user and assistant messages only after a response. Version 1 must not claim automatic conversation persistence for batch results. Result processing may add an explicit integration later.
- Arbitrary agent middleware may transform prompts, short-circuit requests, or depend on a synchronous response. Unless the resolver can execute it with identical semantics, it must throw `UnsupportedBatchFeatureException`; bypassing middleware silently is not acceptable.
- Tool definitions or structured schemas that are preserved in the first request are not proof that Laravel AI's complete synchronous behavior is preserved. Compatibility tests must cover each supported feature.

When Laravel AI adds an official resolved-request API, only the `RequestResolver` implementation should change.

### `BatchProvider`

```php
namespace RefinePhp\LaravelAiBatch\Contracts;

interface BatchProvider
{
    public function name(): string;

    public function submit(BatchSubmission $submission): ProviderBatch;

    public function refresh(ProviderBatch $batch): ProviderBatch;

    public function cancel(ProviderBatch $batch): ProviderBatch;

    /** @return iterable<int, BatchResult> */
    public function results(ProviderBatch $batch): iterable;

    /** @return iterable<int, BatchError> */
    public function errors(ProviderBatch $batch): iterable;
}
```

`BatchSubmission` is an immutable package DTO containing the package UUID, provider name, optional display name, completion window, and a non-empty list of `BatchRequest` objects. It does not need facade-level exposure in version 1. The OpenAI provider uses the package UUID as safe correlation metadata; this aids recovery but is not treated as an idempotency key.

This interface models only the lifecycle that OpenAI has been studied to support. Do not add capability discovery, generic file APIs, arbitrary provider options, or hypothetical provider methods. A future provider should implement this contract only after its lifecycle is researched; otherwise the contract can be revised in a major version.

Provider registration is container/config based, for example:

```php
'providers' => [
    'openai' => OpenAiBatchProvider::class,
],
```

The manager resolves providers through the container. Providers hold their HTTP dependencies through constructor injection. No static provider registry is allowed.

### `BatchRepository`

```php
namespace RefinePhp\LaravelAiBatch\Contracts;

interface BatchRepository
{
    public function save(ProviderBatch $batch): void;

    public function find(string $id): ?ProviderBatch;

    /** @return iterable<int, ProviderBatch> */
    public function pollable(int $limit = 100): iterable;
}
```

`save()` is an idempotent upsert by local UUID. `pollable()` returns non-terminal snapshots in a deterministic order for the polling command; it does not acquire locks or perform network work. Locking and terminal-state monotonicity belong to the polling coordinator / manager.

Avoid `findByProviderBatchId()` in the first public contract unless an implementation use case requires it. Provider IDs are persisted and indexed, but application lookup should use the package UUID to avoid provider ambiguity.

## Exception hierarchy

All package exceptions implement a marker contract (`BatchThrowable`) and extend the package base exception where practical, so applications may catch broadly or specifically.

```text
BatchThrowable
└── BatchException (RuntimeException)
    ├── InvalidBatchRequestException
    │   ├── InvalidCustomIdException
    │   ├── DuplicateCustomIdException
    │   ├── ProviderMismatchException
    │   └── EmptyBatchException
    ├── BatchAlreadySubmittedException
    ├── RequestResolutionException
    │   └── UnsupportedBatchFeatureException
    ├── BatchSubmissionException
    ├── BatchRefreshException
    ├── BatchCancellationException
    ├── BatchResultRetrievalException
    │   └── MalformedBatchOutputException
    ├── BatchNotFoundException
    ├── BatchPersistenceException
    └── BatchConfigurationException
```

Guidelines:

- Exceptions include safe identifiers such as local UUID, provider name, provider batch ID, custom ID, HTTP status, and provider error code where available.
- Exception messages never include authorization headers, the full prompt/request body, complete response bodies, or credentials.
- Provider HTTP exceptions are wrapped with the operation-specific exception and retained as `previous` for debugging.
- Parser errors identify the file and line number without echoing the full line by default.
- `find()` returns `null`; `findOrFail()` throws `BatchNotFoundException`.

## Persistence recommendation

Version 1 should include first-party Eloquent persistence behind `BatchRepository`, because durable status polling and `find()` are core Laravel lifecycle features. It must remain replaceable and must not be required for direct provider operations.

Recommended behavior:

- Ship an `EloquentBatchRepository` and publishable `ai_batches` migration.
- Use a package-owned record model such as `Models\BatchRecord`; avoid `Models\AiBatch`, which is easily confused with the facade.
- Generate the local UUID before remote submission. Save the remote snapshot only after creation succeeds. If remote creation succeeds but persistence fails, throw `BatchPersistenceException` containing the safe provider batch ID so the caller can recover it.
- Provide a `NullBatchRepository` for explicitly stateless applications. `submit()`, `refresh()`, `cancel()`, and result retrieval work when the caller retains the `ProviderBatch`; `find()` / polling cannot.
- Do not silently fall back from a configured database repository to in-memory storage.
- Keep repository hydration independent of Eloquent models so public APIs return `ProviderBatch`, never a model.

Recommended stored fields:

```text
id (UUID primary key)
provider
provider_batch_id (indexed; unique with provider)
name
status
provider_status
input_file_id
output_file_id
error_file_id
request_count
completed_count
failed_count
submitted_at
completed_at
failed_at
expires_at
created_at
updated_at
```

Do not persist prompts, resolved request bodies, JSONL input, authorization headers, output bodies, error-file contents, or arbitrary raw provider responses by default. If metadata is added later, it must be documented as application-controlled and constrained to non-sensitive scalar values.

## Important decisions and tradeoffs

### Manager operations instead of active-record methods

`$batch = AiBatch::refresh($batch)` is slightly more verbose than `$batch->refresh()`, but it keeps `ProviderBatch` immutable, serializable, testable, and free of hidden container access. It also makes remote I/O obvious in code review.

### One provider per pending batch

Laravel AI supports provider failover for synchronous calls, but an uploaded batch is created with one provider. `forProvider()` is therefore required and accepts one `Lab|string`, not an array. Per-item provider mismatch is rejected before upload.

### Endpoint is derived, not configured twice

`PendingBatch` has no public `endpoint()` method. The resolver returns the actual endpoint chosen for each request, and the OpenAI provider validates that all requests use one supported batch endpoint. This avoids drifting from Laravel AI's Responses API selection.

### Safe headers are visible; credentials are not

The resolved DTO retains a safe-header accessor for compatibility with a future official Laravel AI resolved-request object, but provider batch writers never serialize it. Any request requiring per-item headers that the batch format cannot represent is rejected.

### Provider-neutral core, OpenAI-specific validation

Custom ID presence/uniqueness and provider consistency are core invariants. OpenAI endpoint allowlists, completion windows, JSONL limits, ID length/charset, file upload behavior, and raw status mapping stay in `Providers\OpenAI`.

### Results are retrieved, not persisted

Returning a lazy stream preserves correlation and supports large files without making the package a sensitive-output database. Applications decide how to store or dispatch each result.

### No speculative provider capabilities

The initial `BatchProvider` contract is deliberately narrow. There is no lowest-common-denominator request schema, feature flag matrix, provider option bag, or abstract file manager. `ResolvedProviderRequest::body()` remains provider-native, and only the OpenAI implementation is promised in version 1.

## Proposed namespaces

```text
src/
├── BatchManager.php
├── PendingBatch.php
├── Contracts/
│   ├── BatchProvider.php
│   ├── BatchRepository.php
│   ├── BatchThrowable.php
│   └── RequestResolver.php
├── Data/
│   ├── BatchError.php
│   ├── BatchRequest.php
│   ├── BatchResult.php
│   ├── BatchSubmission.php
│   ├── ProviderBatch.php
│   └── ResolvedProviderRequest.php
├── Enums/
│   └── BatchStatus.php
├── Exceptions/
│   └── ...
├── Facades/
│   └── AiBatch.php
├── Providers/
│   └── OpenAI/
├── Repositories/
│   ├── EloquentBatchRepository.php
│   └── NullBatchRepository.php
└── Models/
    └── BatchRecord.php
```

`ProviderBatch` belongs under `Data/`; it is not an active provider service. If the lead prefers the initially suggested root namespace for discoverability, the behavior and dependency boundary should remain unchanged.

## Lead decisions still required

1. Confirm whether immutable manager operations are preferred over putting collaborator-backed methods on `ProviderBatch`.
2. Confirm which Laravel AI version/commit is pinned after the request-resolution research is complete.
3. Confirm the exact version 1 support policy for custom middleware, conversation persistence, local tools, approvals, MCP tools, and sub-agents.
4. Confirm whether Eloquent persistence is the documented default or an opt-in repository.
5. Reconcile `BatchStatus` with the OpenAI research workstream's verified current status list.
