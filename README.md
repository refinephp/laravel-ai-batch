# Laravel AI Batch

Provider-native asynchronous batch workflows for the Laravel AI SDK.

Laravel AI Batch lets an existing [Laravel AI](https://laravel.com/docs/13.x/ai-sdk) agent produce normal synchronous requests and OpenAI Batch API requests without defining its instructions, messages, tools, schema, model options, or provider options twice.

> Run Laravel AI agents through provider-native batch APIs.

## Requirements and compatibility

- PHP 8.3 or newer
- Laravel 12 or 13
- `laravel/ai` 0.9.1 exactly
- A native OpenAI provider connection using `https://api.openai.com/v1`

The exact Laravel AI pin is intentional. Laravel AI does not currently expose a public resolved-request API, so this package isolates and tests one protected v0.9.1 integration point. See [Compatibility](docs/compatibility.md) for the policy and risks.

## Installation

```bash
composer require refinephp/laravel-ai-batch
php artisan vendor:publish --tag=ai-batch-config
php artisan vendor:publish --tag=ai-batch-migrations
php artisan migrate
```

The service provider and `AiBatch` facade are discovered automatically.

Configure the existing native OpenAI connection in `config/ai.php`:

```php
'providers' => [
    'openai' => [
        'driver' => 'openai',
        'key' => env('OPENAI_API_KEY'),
        'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        'store' => env('OPENAI_STORE', true),
    ],
],
```

Laravel AI Batch reads that connection. It does not define or persist a second API key.

## Configuration

`config/ai-batch.php` controls:

- `repository`: `eloquent` by default, or `null` for explicit stateless operation;
- `openai.connection`: the configured native OpenAI connection name;
- upload, lifecycle, download, and connection timeouts;
- maximum request count and generated JSONL bytes;
- the cache store and lease duration used for lifecycle locks.

The default limits match OpenAI: 50,000 requests and 200 MB. Lower them for application-specific safety; do not configure values above OpenAI's limits and expect them to work.

The Eloquent repository stores lifecycle metadata only. With `AI_BATCH_REPOSITORY=null`, submission and lifecycle operations work while the application retains each immutable `ProviderBatch`, but `find()`, polling commands, and durable recovery are unavailable.

## Define a Laravel AI agent

This is a normal Laravel AI agent and can still be used synchronously:

```php
<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final class GeneratePullRequestSummary implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Summarize the pull request accurately in at most five bullets.';
    }
}
```

```php
$response = GeneratePullRequestSummary::make()->prompt($prompt);
```

## Create a batch from the same agent

Laravel AI's real invocation input is a string prompt plus optional attachments and model. `add()` deliberately mirrors that contract; it does not invent an arbitrary context array.

```php
use App\Ai\Agents\GeneratePullRequestSummary;
use Laravel\Ai\Enums\Lab;
use RefinePhp\LaravelAiBatch\Facades\AiBatch;

$firstPrompt = <<<'PROMPT'
Title: Add invoice export

Diff:
... first diff here ...
PROMPT;

$secondPrompt = <<<'PROMPT'
Title: Fix authorization policy

Diff:
... second diff here ...
PROMPT;

$batch = AiBatch::forProvider(Lab::OpenAI)
    ->name('pull-request-summaries')
    ->agent(GeneratePullRequestSummary::class)
    ->add('pr-123', prompt: $firstPrompt)
    ->add('pr-124', prompt: $secondPrompt, model: 'gpt-5.4-mini')
    ->submit();

$localId = $batch->id();
$openAiId = $batch->providerBatchId();
```

Custom IDs must be non-empty and unique. Valid IDs are preserved byte-for-byte and are the only supported result-correlation key.

Every item is resolved before upload. If any prompt, attachment, model, provider option, schema, or generated line is invalid, submission stops before the remote input file is uploaded.

## Attachments

Pass the same Laravel AI attachment objects that the synchronous agent accepts:

```php
use Laravel\Ai\Files\LocalDocument;

$batch = AiBatch::forProvider(Lab::OpenAI)
    ->agent(AnalyzeDocument::class)
    ->add(
        'invoice-42',
        prompt: 'Extract the invoice fields.',
        attachments: [LocalDocument::fromPath(storage_path('app/invoice-42.pdf'))],
    )
    ->submit();
```

Local and storage-backed attachments are read during resolution and may be base64-expanded into JSONL. Application-configured byte limits apply after expansion.

## Resolve and submit provider requests directly

Resolve an item explicitly when you need the provider-ready body or an individually constructed agent. Per-item constructor state belongs on that real agent, not in a second batch context format:

```php
$request = AiBatch::resolve(
    agent: GeneratePullRequestSummary::make(),
    prompt: 'Generate the pull request summary.',
    provider: Lab::OpenAI,
);

$request->provider(); // openai
$request->method();   // POST
$request->endpoint(); // /v1/responses
$request->body();     // final provider-native body
$request->headers();  // always credential-free

$batch = AiBatch::forProvider(Lab::OpenAI)
    ->name('pull-request-summaries')
    ->addRequest('pr-123', $request)
    ->submit();
```

Resolution uses Laravel AI's normal first-request pipeline and makes no provider HTTP call. It preserves instructions, conversation messages, prompt and attachments, model selection, provider options, tools, structured output, tool choice, and supported middleware revisions.

## Retrieve and refresh status

Snapshots are immutable. Reassign the refreshed value:

```php
$batch = AiBatch::findOrFail($localId);
$batch = AiBatch::refresh($batch);

if ($batch->isTerminal()) {
    // Results or validation errors are now inspectable.
}
```

Important fields include `status()`, `providerStatus()`, `requestCount()`, `completedCount()`, `failedCount()`, `inputFileId()`, `outputFileId()`, `errorFileId()`, and lifecycle timestamps.

## Poll with queues and the scheduler

Polling is opt-in. The command dispatches one idempotent queued job per pollable batch:

```bash
php artisan ai:batch:poll --limit=100
php artisan queue:work
```

Add an application-owned schedule in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('ai:batch:poll --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

Each lifecycle operation uses an atomic per-batch cache lock, re-reads the latest snapshot inside the lock, and skips terminal batches. Duplicate jobs and concurrent cancellation therefore converge safely. The configured cache store must support atomic locks.

Inspect locally persisted status without a provider call:

```bash
php artisan ai:batch:status 019f7d2e-1234-7000-8000-123456789abc
```

See [Lifecycle](docs/lifecycle.md) for locking, retries, and transition behavior.

## Retrieve results

OpenAI output order is not input order. The package parses both output and error files and yields correlated outcomes by `custom_id`:

```php
$batch = AiBatch::findOrFail($localId);
$batch = AiBatch::refresh($batch);

$responsesByPullRequest = [];

foreach (AiBatch::results($batch) as $result) {
    if (! $result->successful()) {
        $error = $result->error();

        logger()->warning('AI batch item failed', [
            'custom_id' => $result->customId(),
            'code' => $error?->code(),
        ]);

        continue;
    }

    $responsesByPullRequest[$result->customId()] = $result->response();
}
```

Results are lazy and are not stored by the package. A malformed, duplicate, partial, or incomplete terminal result set throws a purpose-specific exception, possibly after earlier lazy records have been yielded; make application result processing idempotent.

## Handle per-item and validation errors

`BatchResult::error()` represents an HTTP or provider execution failure for one custom ID. Batch-level input validation errors do not always identify a custom ID:

```php
foreach (AiBatch::errors($batch) as $error) {
    logger()->warning('AI batch item failed', [
        'custom_id' => $error->customId(),
        'code' => $error->code(),
        'parameter' => $error->parameter(),
        'line' => $error->line(),
    ]);
}
```

Avoid logging `$error->message()` unless the application's privacy policy permits provider-supplied text. Do not log complete response bodies.

## Cancel a batch

```php
$batch = AiBatch::findOrFail($localId);
$batch = AiBatch::cancel($batch);
```

Or use the lock-aware command:

```bash
php artisan ai:batch:cancel 019f7d2e-1234-7000-8000-123456789abc
```

Cancellation is asynchronous. OpenAI can report `cancelling` for up to ten minutes before `cancelled`, and partial results may remain available. Poll until terminal. If a cancellation response is lost, the package reconciles with a retrieval call and never blindly repeats the cancellation POST.

## Jobs and events

Version one ships the retryable `PollBatch` queued job used by `ai:batch:poll`. It intentionally does not ship automatic result-handler jobs or package events with sensitive payloads. Applications should dispatch their own idempotent processing jobs while iterating `AiBatch::results()`.

## Testing an application

Normal package tests never contact OpenAI. In application tests, use Laravel HTTP fakes for lifecycle endpoints and prevent stray requests:

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();
Http::fake([
    'api.openai.com/v1/files' => Http::response(['id' => 'file-input'], 200),
    'api.openai.com/v1/batches' => Http::response([
        'id' => 'batch-test',
        'status' => 'validating',
        'input_file_id' => 'file-input',
        'request_counts' => ['total' => 1, 'completed' => 0, 'failed' => 0],
        'created_at' => 1_784_476_800,
    ], 200),
]);
```

Do not call Laravel AI's `Agent::fake()` when testing request resolution: this package rejects active agent fakes because they bypass the native OpenAI request builder. Use a real test agent plus `Http::preventStrayRequests()` to prove resolution itself sends nothing.

## Security and privacy

- Authorization, cookies, and API keys never enter `ResolvedProviderRequest` or JSONL.
- Temporary input paths and filenames are package-generated; files are deleted after upload/create succeeds or fails.
- Prompt bodies, resolved requests, JSONL, output contents, and arbitrary raw provider responses are not persisted.
- Provider output is treated as untrusted JSON. The package never deserializes arbitrary PHP objects.
- Exceptions contain safe identifiers and bounded provider messages, not authorization headers or complete bodies.
- Keep custom IDs non-sensitive because they are sent to OpenAI and stored in provider files.
- OpenAI output files are temporary remote resources. Retrieve needed results promptly and apply your own retention policy.

Report vulnerabilities according to [SECURITY.md](SECURITY.md).

## OpenAI limitations

Version one supports only native OpenAI Batch requests targeting `POST /v1/responses` with a `24h` completion window. It does not claim support for chat completions, embeddings, legacy completions, moderation, images, or videos even though OpenAI Batch supports some of them.

One item is one initial provider request. Function, sub-agent, and MCP tool definitions can be serialized, but the package does not execute Laravel-side tool or approval continuations after asynchronous output. It also does not append completed turns to Laravel AI conversation storage.

Provider failover, streaming, real-time calls, custom OpenAI-compatible URLs, Azure OpenAI, dashboards, workflow engines, automatic schedules, and automatic prompt/result storage are outside version one.

## Roadmap

- Adopt an official Laravel AI resolved-provider-request API when available.
- Expand Laravel AI compatibility through deliberate versioned adapters and parity tests.
- Research continuation workflows before offering Laravel-side tool execution.
- Study another provider end-to-end before changing the provider contract.
- Consider operational recovery/listing tools for ambiguous submissions.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Changes to compatibility adapters or public contracts must include research, parity tests, and documentation. Run:

```bash
composer quality
composer validate --strict
```

## License

Laravel AI Batch is open-source software licensed under the [MIT license](LICENSE.md).
