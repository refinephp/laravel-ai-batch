# Compatibility

## Supported matrix

| PHP | Laravel | Laravel AI |
| --- | --- | --- |
| 8.3 | 12, 13 | 0.9.x, 0.10.x, 0.11.x |
| 8.4 | 12, 13 | 0.9.x, 0.10.x, 0.11.x |
| 8.5 | 13 | 0.9.x, 0.10.x, 0.11.x |

Laravel 12 is excluded on PHP 8.5 in CI to match the current upstream Laravel AI matrix.

## Why Laravel AI is bounded rather than pinned

Laravel AI has no public API for resolving a provider request without sending it. Laravel AI Batch uses the public gateway replacement seam, then calls exactly one protected `OpenAiGateway::buildStepBody()` method in a package-private adapter.

Because Laravel AI is pre-1.0, that method and `AgentPrompt::__construct()` are not public API. The adapter therefore asserts their *shape* rather than an exact version:

- `LaravelAiVersion::assertSupported()` reflects on both signatures at resolution time and verifies that the leading parameters this package passes positionally are unchanged. Parameters Laravel AI appends with defaults are tolerated. A rename, a reorder, or a newly required parameter fails with an actionable message naming the drifted signature.
- Reflection tests assert the same contract in CI, plus each failure mode against fixture signatures.
- Structural parity tests compare batch resolution against the real synchronous request captured by Laravel HTTP fakes.
- No-stray-request assertions cover batch resolution.

Both coupling points are byte-identical from 0.9.0 through 0.11.2, and the full suite passes unchanged against 0.9.0, 0.9.1, 0.10.x, and 0.11.x. The 0.10 and 0.11 breaking changes are confined to conversation participants, event constructors, streaming exceptions, and provider failover, none of which this package touches.

Laravel AI 0.9.0 is the hard floor. It is the release that introduced the step-based text generation architecture this package hooks: `StepContext`, `StepResponse`, the `StepTextGateway` contract, and `OpenAiGateway::buildStepBody()` are all absent from 0.8.1 and earlier, so the adapter cannot load against them at all.

Raising the upper bound still requires a deliberate adapter review and full parity coverage against the new minor. A wider version claim without those checks will not be accepted.

## Behavior that varies across supported Laravel AI versions

- Laravel AI 0.11 routes the `CacheInstructions` and `CacheToolDefinitions` agent attributes into the request body, so batches submitted under 0.11 may carry prompt-caching fields that 0.9 and 0.10 omit.
- The native OpenAI provider's default, cheapest, and smartest text model names changed in 0.11. Agents that do not pin a model explicitly will resolve a different model name after upgrading. Pin the model on the agent or in provider configuration if batch output must stay comparable across the upgrade.

## Supported request behavior

For the initial OpenAI Responses API step, tests cover instructions, existing messages, current prompt, attachments, explicit and agent-selected models, model options, provider options, tools, structured schemas, strictness, and middleware prompt revision.

Provider failover arrays, active Laravel AI fakes, non-native providers, custom base URLs, middleware that returns without reaching a provider request, streaming, queued-agent execution, and approval continuation are unsupported and fail specifically.

Tool definitions can be serialized, but Laravel-side continuation is not implemented. Existing conversation history can be read; the eventual batch response is not automatically written back to Laravel AI's conversation store.

## Upstream path

If Laravel AI adds an official resolved-provider-request API, the `RequestResolver` binding can change without altering this package's public DTOs or batch lifecycle API.
