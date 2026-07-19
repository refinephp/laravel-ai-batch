# Compatibility

## Supported matrix

| PHP | Laravel | Laravel AI |
| --- | --- | --- |
| 8.3 | 12, 13 | 0.9.1 |
| 8.4 | 12, 13 | 0.9.1 |
| 8.5 | 13 | 0.9.1 |

Laravel 12 is excluded on PHP 8.5 in CI to match the current upstream Laravel AI matrix.

## Why Laravel AI is pinned exactly

Laravel AI v0.9.1 has no public API for resolving a provider request without sending it. Laravel AI Batch uses the public gateway replacement seam, then calls exactly one protected `OpenAiGateway::buildStepBody()` method in a package-private adapter.

The adapter is guarded by:

- an exact runtime installed-version check;
- reflection tests for the gateway and prompt signatures;
- structural parity tests against the real synchronous request captured by Laravel HTTP fakes;
- no-stray-request assertions during batch resolution.

The current post-v0.9.1 branch already changes approval and generation-loop internals. Supporting a new Laravel AI version requires a deliberate adapter review, full parity coverage, and an updated Composer constraint. A wider version claim without those checks will not be accepted.

## Supported request behavior

For the initial OpenAI Responses API step, tests cover instructions, existing messages, current prompt, attachments, explicit and agent-selected models, model options, provider options, tools, structured schemas, strictness, and middleware prompt revision.

Provider failover arrays, active Laravel AI fakes, non-native providers, custom base URLs, middleware that returns without reaching a provider request, streaming, queued-agent execution, and approval continuation are unsupported and fail specifically.

Tool definitions can be serialized, but Laravel-side continuation is not implemented. Existing conversation history can be read; the eventual batch response is not automatically written back to Laravel AI's conversation store.

## Upstream path

If Laravel AI adds an official resolved-provider-request API, the `RequestResolver` binding can change without altering this package's public DTOs or batch lifecycle API.
