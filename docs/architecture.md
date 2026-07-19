# Architecture

Status: accepted for version one on 2026-07-19.

## Principles

- Existing Laravel AI agents remain the source of instructions, messages, attachments, tools, schemas, models, and provider options.
- Public input mirrors `Agent::prompt()`: a string prompt, attachments, one provider, and an optional model.
- Provider-native request bodies remain provider-native; the core does not invent a lowest-common-denominator payload.
- Only studied OpenAI lifecycle behavior is abstracted.
- Immutable data objects represent requests, remote snapshots, results, and errors. Side effects remain on injected services.
- Unsupported behavior fails specifically and never disappears silently.
- Credentials, prompts, and provider result bodies are neither logged nor persisted by default.

## Public usage

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

$batch = AiBatch::refresh($batch);

foreach (AiBatch::results($batch) as $result) {
    if ($result->successful()) {
        process($result->customId(), $result->response());
    } else {
        report($result->error());
    }
}
```

`add()` does not accept an arbitrary context array because Laravel AI has no such invocation contract. An agent with per-item constructor state uses the real agent instance and lower-level resolver:

```php
$request = AiBatch::resolve(
    agent: GeneratePullRequestSummary::make(pullRequest: $pullRequest),
    prompt: 'Generate the pull request summary.',
    provider: Lab::OpenAI,
);

$batch = AiBatch::forProvider(Lab::OpenAI)
    ->addRequest('pr-123', $request)
    ->submit();
```

## Core services

`BatchManager` is the application-facing coordinator and facade target. It creates pending builders, resolves requests, finds stored snapshots, refreshes/cancels batches, and retrieves lazy result/error streams.

`PendingBatch` is the one intentionally mutable fluent builder. It fixes one provider, optional name, one agent definition, and a unique set of items. It resolves and validates every item before any upload and refuses a second `submit()` call.

`RequestResolver` is the narrow compatibility seam around Laravel AI. Its public contract accepts only a Laravel AI `Agent`, prompt string, attachments, provider, and model and returns a package-owned `ResolvedProviderRequest`.

`BatchProvider` owns the studied remote lifecycle: submit, refresh, cancel, results, and errors. It does not own persistence, queues, generic file management, or hypothetical provider capability discovery.

`BatchRepository` persists immutable `ProviderBatch` snapshots through idempotent upsert and returns deterministically ordered pollable snapshots. Eloquent is the documented default; a configured null repository enables explicit stateless use.

## Data model

- `ResolvedProviderRequest`: configured provider name, `POST`, `/v1/responses`, provider body, and no credential headers.
- `BatchRequest`: caller custom ID plus one resolved request.
- `BatchSubmission`: local UUID, provider, optional name, completion window, and a non-empty request list.
- `ProviderBatch`: immutable local/remote lifecycle snapshot including raw provider status and validation errors.
- `BatchResult`: one correlated item outcome, including HTTP status/request ID, parsed response, or `BatchError`.
- `BatchError`: nullable custom ID for batch-level errors, code, safe message, parameter, optional line/status/request ID.
- `BatchStatus`: the eight researched OpenAI states plus `Unknown`; terminality and cancellability are explicit enum behavior.

`ProviderBatch::id()` is the stable local UUID. `providerBatchId()` is the OpenAI identifier. Application lookup uses the local ID; provider calls use the remote ID.

Results are not persisted. `results()` lazily combines successful output-file and failed error-file item outcomes and correlates them by custom ID. `errors()` is a convenience lazy stream. Batch validation errors are available on the batch snapshot without pretending they always have a custom ID.

## Request resolution

The v0.9.1 adapter:

1. Resolves and validates a single native OpenAI connection.
2. Resolves explicit/method/attribute/default model precedence.
3. Clones the cached OpenAI provider.
4. Installs a private `OpenAiGateway` capture subclass through `useTextGateway()`.
5. Runs the normal provider prompt pipeline with an internal `AgentPrompt`.
6. Captures inherited `buildStepBody()` output at step zero by throwing a private sentinel before HTTP construction.
7. Returns the package DTO and proves no HTTP request occurred.

The adapter lives in a compatibility namespace and is covered by signature and parity tests. When Laravel AI exposes an official resolved-request API, only this binding changes.

One batch item is one initial provider step. Function and provider tool definitions may be serialized exactly, but this package does not execute Laravel-side continuations from asynchronous output in version one. Documentation and result shapes make that limitation explicit.

## OpenAI provider

`Providers\OpenAI\OpenAiBatchProvider` validates submissions, delegates deterministic JSONL construction to `OpenAiJsonlWriter`, delegates transport to `OpenAiBatchClient`, maps snapshots through `OpenAiStatusMapper`, and parses untrusted output through `OpenAiJsonlParser`.

The provider enables only `/v1/responses`. Every request must be `POST`, use the selected configured OpenAI provider, contain an object body and non-empty model, and share one endpoint/model. The writer enforces unique IDs, 50,000 records, 200 MB, compact Unicode-safe JSON, and final newlines before upload.

The HTTP client owns bearer authentication, base URL, bounded timeouts, multipart upload, create/retrieve/cancel calls, and file downloads. It never accepts or logs per-item prompts. Unsafe automatic retries are prohibited for upload/create; ambiguous state is reported with safe resource and request identifiers.

## Persistence and polling

The publishable `ai_batches` table stores only lifecycle metadata:

```text
id, provider, provider_batch_id, name, status, provider_status,
input_file_id, output_file_id, error_file_id,
request_count, completed_count, failed_count,
submitted_at, completed_at, failed_at, expires_at, validation_errors,
created_at, updated_at
```

`validation_errors` stores only parsed safe fields (custom ID, code, bounded message, parameter, line, status, and provider request ID). It does not store input prompts, resolved bodies, JSONL, output/error contents, credentials, or arbitrary raw responses.

Polling uses a per-local-batch atomic lock, re-reads the latest snapshot after lock acquisition, skips terminal batches, performs one provider retrieval, and saves only a legal monotonic transition. Duplicate jobs therefore converge safely. Scheduled polling is opt-in and documented; no schedule is registered automatically.

## Exceptions

All package exceptions implement `BatchThrowable` and extend `BatchException` where appropriate. Purpose-specific branches cover invalid/duplicate requests, unsupported resolution features or versions, already-submitted builders, lifecycle transport operations, ambiguous submission, persistence, not-found state, and malformed output.

Exception context may include safe local/provider IDs, operation, HTTP status, provider error code, request ID, file ID, and line number. It must not include authorization, cookies, keys, full prompts, full request bodies, or complete provider output lines.

## Compatibility policy

- Composer pins Laravel AI v0.9.1 exactly.
- Public APIs never expose Laravel AI gateway/orchestration internals.
- CI covers supported PHP and Laravel combinations plus the fixed Laravel AI version.
- Contract tests fail clearly when protected signatures change.
- Payload-parity tests compare the resolver with the actual synchronous request for every supported feature family.
- Compatibility expands only when those tests pass for a deliberate new adapter/version range.

## Security boundaries

- Resolved requests contain no authorization or cookie headers.
- JSONL uses validated temporary files with safe generated names, bounded bytes, and guaranteed cleanup.
- Provider output is decoded as JSON arrays only; arbitrary PHP deserialization is forbidden.
- Logs and default persistence exclude prompts and provider bodies.
- Custom IDs are validated but never normalized, preserving exact correlation.
- Remote IDs and metadata are treated as untrusted strings at all boundaries.

## Deliberately excluded from version one

- providers other than native OpenAI;
- OpenAI batch endpoints other than `/v1/responses`;
- provider failover;
- streaming or real-time requests;
- automatic Laravel-side tool/approval continuation;
- automatic conversation-result persistence;
- scheduled polling without application opt-in;
- dashboards, workflow engines, and queue replacement behavior;
- automatic storage of sensitive prompts or result bodies.
