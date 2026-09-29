# Compatibility

## Supported matrix

| PHP | Laravel | Laravel AI |
| --- | --- | --- |
| 8.3 | 12, 13 | 0.9.x, 0.10.x, 0.11.x, 1.0.x |
| 8.4 | 12, 13 | 0.9.x, 0.10.x, 0.11.x, 1.0.x |
| 8.5 | 13 | 0.9.x, 0.10.x, 0.11.x, 1.0.x |

Laravel 12 is excluded on PHP 8.5 in CI to match the current upstream Laravel AI matrix.

## Why Laravel AI is bounded rather than pinned

Laravel AI has no public API for resolving a provider request without sending it. Laravel AI Batch uses the public gateway replacement seam, then calls exactly one protected `OpenAiGateway::buildStepBody()` method in a package-private adapter.

That method and `AgentPrompt::__construct()` are not public API, and Laravel AI 1.0 did not make them public. The adapter therefore asserts their *shape* rather than an exact version:

- `LaravelAiVersion::assertSupported()` reflects on both signatures at resolution time and verifies that the leading parameters this package passes positionally are unchanged. Parameters Laravel AI appends with defaults are tolerated. A rename, a reorder, or a newly required parameter fails with an actionable message naming the drifted signature.
- Reflection tests assert the same contract in CI, plus each failure mode against fixture signatures.
- Structural parity tests compare batch resolution against the real synchronous request captured by Laravel HTTP fakes.
- No-stray-request assertions cover batch resolution.

Both coupling points are byte-identical from 0.9.0 through 0.11.2, and their leading parameters are unchanged in 1.0.0. The full suite passes against 0.9.0, 0.9.1, 0.10.x, 0.11.x, and 1.0.x. The 0.10 and 0.11 breaking changes are confined to conversation participants, event constructors, streaming exceptions, and provider failover, none of which this package touches. Of the 1.0 breaking changes, only the agent middleware contract affects request resolution; see [Agent middleware](#agent-middleware).

Laravel AI 0.9.0 is the hard floor. It is the release that introduced the step-based text generation architecture this package hooks: `StepContext`, `StepResponse`, the `StepTextGateway` contract, and `OpenAiGateway::buildStepBody()` are all absent from 0.8.1 and earlier, so the adapter cannot load against them at all.

Raising the upper bound still requires a deliberate adapter review and full parity coverage against the new minor. A wider version claim without those checks will not be accepted.

## Behavior that varies across supported Laravel AI versions

- Laravel AI 0.11 routes the `CacheInstructions` and `CacheToolDefinitions` agent attributes into the request body, so batches submitted under 0.11 may carry prompt-caching fields that 0.9 and 0.10 omit.
- Laravel AI 1.0 changes the agent middleware contract. See [Agent middleware](#agent-middleware).
- The native OpenAI provider's default, cheapest, and smartest text model names changed in 0.11, and the default changed again to GPT-6 in 1.0. Agents that do not pin a model explicitly will resolve a different model name after upgrading. Pin the model on the agent or in provider configuration if batch output must stay comparable across the upgrade.

## Agent middleware

Request resolution runs the agent's middleware, so middleware must match the installed Laravel AI release:

- Laravel AI 0.9 to 0.11 run agent middleware once around the whole run. `handle()` receives a `Laravel\Ai\Prompts\AgentPrompt`.
- Laravel AI 1.0 runs agent middleware around each generation step. `handle()` receives a `Laravel\Ai\PendingStep` and returns the result of `$next($step)` or a `Laravel\Ai\Gateway\StepResponse`.

Batch resolution captures only the first generation step, so 1.0 middleware runs once per resolved request.

```php
// Laravel AI 0.9 to 0.11...
public function handle(AgentPrompt $prompt, Closure $next)
{
    return $next($prompt->append('Answer in English.'));
}

// Laravel AI 1.0...
public function handle(PendingStep $step, Closure $next)
{
    return $next($step->withInstructions($step->instructions.PHP_EOL.'Answer in English.'));
}
```

The two contracts are mutually exclusive, so one middleware class cannot target both. Before resolving, the resolver reflects on each middleware's first parameter. If that parameter cannot accept the value the installed release passes, it throws a `RequestResolutionException` that names the middleware. Untyped, `object`, and `mixed` parameters are accepted. See the [Laravel AI 1.0 upgrade guide](https://github.com/laravel/ai/blob/1.x/UPGRADE.md) for the full migration.

## Supported request behavior

For the initial OpenAI Responses API step, tests cover instructions, existing messages, current prompt, attachments, explicit and agent-selected models, model options, provider options, tools, structured schemas, strictness, and middleware prompt revision.

Provider failover arrays, active Laravel AI fakes, non-native providers, custom base URLs, middleware that returns without reaching a provider request, middleware written for a different Laravel AI middleware contract, streaming, queued-agent execution, and approval continuation are unsupported and fail specifically.

Tool definitions can be serialized, but Laravel-side continuation is not implemented. Existing conversation history can be read; the eventual batch response is not automatically written back to Laravel AI's conversation store.

## Upstream path

If Laravel AI adds an official resolved-provider-request API, the `RequestResolver` binding can change without altering this package's public DTOs or batch lifecycle API.
