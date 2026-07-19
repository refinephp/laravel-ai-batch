# Usage

The [README](../README.md) contains the complete installation-to-result walkthrough. This document records the public API rules that are easy to miss.

## Input model

`PendingBatch::add()` accepts the same request-body inputs as Laravel AI's synchronous `Agent::prompt()` path: a string prompt, attachments, and an optional model. The provider is fixed once by `forProvider()`.

Agents with per-item constructor state should be instantiated with their normal `::make(...)` factory and passed to `AiBatch::resolve()`. The resulting request is submitted through `addRequest()`.

## Snapshots and side effects

`ProviderBatch` is immutable. Remote I/O lives on `BatchManager` and its facade:

```php
$batch = AiBatch::findOrFail($id);
$batch = AiBatch::refresh($batch);
$batch = AiBatch::cancel($batch);
$results = AiBatch::results($batch);
$errors = AiBatch::errors($batch);
```

The local UUID returned by `id()` is used for repository lookup and commands. `providerBatchId()` is OpenAI's remote identifier.

## Stateless operation

Set `AI_BATCH_REPOSITORY=null` to use `NullBatchRepository`. Submission, refresh, cancellation, and result retrieval remain available while the application retains the snapshot. `find()`, scheduler-driven polling, and durable recovery intentionally do not work without persistence.

## Lazy results

Result streams can represent tens of thousands of rows. They download provider files on iteration and yield entries lazily. Handle each custom ID idempotently because a malformed or incomplete record discovered later in the file will throw after earlier rows have already been yielded.

## Tool boundary

The resolver preserves the exact first request, including tool definitions and tool choice. Provider-native tools can run inside OpenAI. Laravel-side tools, agent tools, MCP tools, and approvals require PHP continuation after an asynchronous response; version one exposes their raw first-step result but does not continue the agent loop.
