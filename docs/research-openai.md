# OpenAI Batch API research and driver notes

Checked: **2026-07-19**

Scope: current OpenAI Batch API behavior and a focused OpenAI driver design for Laravel AI Batch. This document does not define the package's shared public contracts.

## Official sources

- [Batch API guide](https://developers.openai.com/api/docs/guides/batch)
- [Batches API reference](https://developers.openai.com/api/reference/resources/batches)
- [Create batch reference](https://developers.openai.com/api/reference/resources/batches/methods/create)
- [Cancel batch reference](https://developers.openai.com/api/reference/resources/batches/methods/cancel)
- [Files API reference](https://developers.openai.com/api/reference/resources/files)
- [Upload file reference](https://developers.openai.com/api/reference/resources/files/methods/create)
- [Retrieve file content reference](https://developers.openai.com/api/reference/resources/files/methods/content)
- [OpenAI API error guide](https://developers.openai.com/api/docs/guides/error-codes)
- [API compatibility and request IDs](https://developers.openai.com/api/reference/overview)
- [Live Batches API endpoint](https://api.openai.com/v1/batches) (credential-free 401 error-envelope check only)

The official developer-docs MCP server was not available in the current process. It was added at `https://developers.openai.com/mcp`, but a process restart is required before it becomes callable, so the research above was verified against the same current official pages on `developers.openai.com`.

## Confirmed workflow

1. Produce one UTF-8 `.jsonl` input file. Every line is one complete request input object; blank lines are not records and should be rejected locally.
2. Upload it using `POST /v1/files` as multipart form data with `purpose=batch`.
3. Create the batch using `POST /v1/batches` with the uploaded file ID, one supported endpoint, and `completion_window: "24h"`.
4. Poll `GET /v1/batches/{batch_id}` until the returned status is terminal.
5. Optionally request cancellation with `POST /v1/batches/{batch_id}/cancel`.
6. Download successful output from `GET /v1/files/{output_file_id}/content` and failed-item output from `GET /v1/files/{error_file_id}/content` when those IDs are present.

`GET /v1/batches` also exists for paginated listing (`after`, `limit` from 1 to 100, default 20). Listing is useful for operations and ambiguous-submit recovery, but it does not need to be part of the initial high-level package API.

## Supported underlying endpoints

As of the checked date, a batch may target exactly one of:

- `/v1/responses`
- `/v1/chat/completions`
- `/v1/embeddings`
- `/v1/completions`
- `/v1/moderations`
- `/v1/images/generations`
- `/v1/images/edits`
- `/v1/videos`

Only `POST` is supported for each JSONL request line. A video's batch request must be JSON, not multipart; referenced assets must already be uploaded or remotely addressable.

The provider supports more endpoints than this package's agent-oriented first release should claim. The initial `OpenAiBatchProvider` should accept only endpoint(s) that the Laravel AI request resolver actually emits and that are covered by integration tests. Most likely candidates are `/v1/responses` and/or `/v1/chat/completions`, but that must follow the Laravel AI execution-path research rather than be guessed. Media, embeddings, moderation, and legacy completions should remain explicitly unsupported at the package layer until there is a corresponding Laravel AI request-resolution use case.

Model availability is a second gate: Batch is available for many, but not all, models. OpenAI directs clients to each model's reference page. The driver should not maintain a speculative, fast-staling model allowlist; let OpenAI validate model eligibility and surface its error.

## JSONL request contract and validation

Every line has this shape:

```json
{"custom_id":"pr-123","method":"POST","url":"/v1/responses","body":{"model":"gpt-5.4-mini","input":"Summarize pull request 123."}}
```

Confirmed rules:

- `custom_id` is a developer-provided string and must be unique within the batch.
- `method` is currently exactly `POST`.
- `url` is a relative API URL from the supported endpoint list and must agree with the endpoint supplied when creating the batch.
- `body` uses the same JSON parameters as a direct call to the underlying endpoint.
- One input file can contain requests to only one model.
- A batch input file may contain at most 50,000 request lines and be at most 200 MB.
- An embeddings batch has an additional maximum of 50,000 embedding inputs across all request bodies, not merely 50,000 lines.
- Batch files must be uploaded with `purpose=batch`.

Recommended local validation before any upload:

- encode each line independently as a JSON object and reject JSON encoding failures;
- reject a missing/empty/duplicate `custom_id` (the docs do not currently publish a maximum ID length, so do not invent one);
- require `POST`, a supported/driver-enabled URL, and an object body;
- require all lines to have the same URL and the same non-empty `body.model`;
- ensure the create-batch endpoint equals every line's URL;
- count requests and bytes while streaming generation, including newline bytes;
- reject more than 50,000 lines or more than 200 MB before upload;
- for embeddings, count every element when `body.input` represents multiple inputs;
- terminate every encoded record with `\n`, never pretty-print across lines, and do not emit a JSON array;
- reject request-specific headers that affect semantics, because the JSONL request input object has no headers field. Authentication and content type belong to lifecycle HTTP calls, not individual lines.

OpenAI remains authoritative for the full endpoint body schema and model eligibility. Local validation should catch deterministic envelope and package-scope errors, not duplicate every evolving OpenAI request schema.

## File upload and retention

`POST /v1/files` is multipart form data with:

- `file`: the actual file object/stream, required;
- `purpose`: `batch`, required;
- optional `expires_after`: `{ "anchor": "created_at", "seconds": N }` where `N` is 3,600 through 2,592,000 (1 hour through 30 days).

The Files API has a general per-file limit of 512 MB, but Batch's stricter 200 MB limit governs batch input. The upload endpoint is documented at 1,000 requests per minute per authenticated user, and project file storage is documented at 2.5 TB. Files uploaded with `purpose=batch` expire after 30 days by default.

Create-batch also accepts optional `output_expires_after` with the same 1-hour-to-30-day range and `created_at` anchor. The anchor is the generated file's creation time, not the Batch object's creation time. Without an override, the Batch guide says generated output is automatically deleted after 30 days. Consumers therefore should fetch and persist needed results promptly rather than treating provider file IDs as permanent storage.

## Create-batch request

```json
{
  "input_file_id": "file_batch_input_123",
  "endpoint": "/v1/responses",
  "completion_window": "24h",
  "metadata": {
    "application_batch_id": "local-batch-123"
  },
  "output_expires_after": {
    "anchor": "created_at",
    "seconds": 2592000
  }
}
```

Required fields are `input_file_id`, `endpoint`, and `completion_window`. Only `24h` is currently supported. Metadata is optional and limited to 16 string key/value pairs; keys have a maximum of 64 characters and values 512 characters.

The package should use a stable local batch identifier in metadata when possible. It assists operator correlation and recovery, but it is not documented as an idempotency key and must not be treated as one.

## Batch object response shape

The create, retrieve, and cancel operations return a Batch object. Important fields are:

```json
{
  "id": "batch_abc123",
  "object": "batch",
  "endpoint": "/v1/responses",
  "model": "gpt-5.4-mini",
  "errors": null,
  "input_file_id": "file_batch_input_123",
  "completion_window": "24h",
  "status": "in_progress",
  "output_file_id": null,
  "error_file_id": null,
  "created_at": 1784476800,
  "in_progress_at": 1784476810,
  "expires_at": 1784563200,
  "finalizing_at": null,
  "completed_at": null,
  "failed_at": null,
  "expired_at": null,
  "cancelling_at": null,
  "cancelled_at": null,
  "request_counts": {
    "total": 2,
    "completed": 1,
    "failed": 0
  },
  "metadata": {
    "application_batch_id": "local-batch-123"
  }
}
```

All lifecycle timestamps are Unix seconds and most are nullable. `model`, `request_counts`, and `usage` are documented as optional. Batch-level `usage` includes input, cached input, output, reasoning, and total tokens and is only populated for batches created after 2025-09-07.

Parsers must tolerate newly added optional response properties. OpenAI's compatibility policy explicitly treats new optional JSON fields as backwards-compatible.

## Status mapping

OpenAI exposes exactly these eight documented status values:

| OpenAI status | Meaning | Suggested provider-neutral state | Terminal? |
|---|---|---|---|
| `validating` | Input file is being validated | pending/validating | No |
| `failed` | Input file failed validation | failed | Yes |
| `in_progress` | Validation passed and requests are executing | running | No |
| `finalizing` | Execution ended and result files are being prepared | finalizing | No |
| `completed` | Result files are ready | completed | Yes |
| `expired` | The batch did not finish inside 24 hours | expired | Yes |
| `cancelling` | Cancellation requested; in-flight work is draining | cancelling | No |
| `cancelled` | Cancellation finished | cancelled | Yes |

The shared domain should preserve the raw provider status and have an unknown/fallback mapping even if it models these eight values today. OpenAI considers adding response fields backwards-compatible, and a future status must not crash hydration or be silently misreported as failure.

Important distinction: batch status `failed` means file validation failed. Individual request failures can exist in a `completed`, `expired`, or `cancelled` batch and are represented by `request_counts.failed` plus `error_file_id`. Therefore `completed` does not mean every item succeeded.

## Output and error JSONL

Output order is explicitly not guaranteed to match input order. Correlation must always use `custom_id`.

A successful line has an endpoint-specific response body wrapped in the common batch envelope:

```json
{"id":"batch_req_success_123","custom_id":"pr-123","response":{"status_code":200,"request_id":"req_provider_123","body":{"id":"resp_123","object":"response","status":"completed","output":[],"usage":{"input_tokens":21,"output_tokens":8,"total_tokens":29}}},"error":null}
```

A non-HTTP execution failure has no response:

```json
{"id":"batch_req_expired_456","custom_id":"pr-124","response":null,"error":{"code":"batch_expired","message":"This request could not be executed before the completion window expired."}}
```

The common request output object is:

- `id`: provider batch-request ID;
- `custom_id`: caller correlation ID;
- `response`: nullable object with `status_code`, `request_id`, and endpoint-specific JSON `body`;
- `error`: nullable object with machine-readable `code` and human-readable `message` for non-HTTP failures.

The reference currently enumerates these non-HTTP codes:

- `batch_expired`
- `batch_cancelled`
- `request_timeout`

Do not make that list exhaustive in parsing. Preserve unknown error codes and messages.

Result classification should be:

1. `response != null` and HTTP 2xx: successful item; hydrate the endpoint-specific body.
2. `response != null` and non-2xx: failed item; preserve status code, request ID, and response body (normally containing the API's structured error details).
3. `response == null` and `error != null`: failed item caused by batch execution rather than an HTTP response.
4. Any other combination: malformed provider output; surface a parse exception containing the batch ID, file ID, line number, and a safely truncated line.

The successful output file contains one line per successful request. Failed request records are written to the file identified by `error_file_id`. Either file ID can be null. A result/error read while the batch is non-terminal should report "not ready" rather than look empty; after a terminal state, a missing corresponding file ID can safely produce an empty result/error stream.

## Validation failures, expiration, and cancellation

Batch-level validation errors are separate from per-item error-file records. On validation failure, the Batch object's `errors` field can contain:

```json
{
  "object": "list",
  "data": [
    {
      "code": "invalid_request",
      "line": 2,
      "message": "A human-readable validation message.",
      "param": "body.model"
    }
  ]
}
```

`code`, `line`, `message`, and `param` are all documented as optional. The driver should not assume every validation error points to a line or parameter.

When a batch expires, unfinished requests are cancelled, completed responses remain available in the output file, and expired items are written to the error file with `code=batch_expired`. Charges still apply to completed requests.

Cancellation is asynchronous. `POST /v1/batches/{id}/cancel` returns the current Batch object; status can remain `cancelling` for up to 10 minutes before becoming `cancelled`. Partial results, if any, are available afterward. Calling code must continue polling and must not equate an accepted cancel request with a terminal cancellation.

## Limits and operational constraints

- Completion window: only `24h`.
- Requests per batch: 50,000 maximum.
- Input file size: 200 MB maximum.
- Embeddings: 50,000 total embedding inputs across all lines.
- Batch creation: 2,000 batches per hour.
- Enqueued prompt tokens: a separate per-model limit shown in the Platform limits/settings and model pages; it varies by model and usage tier.
- Output tokens: no Batch-specific output-token limit is currently documented.
- Batch rate limits use a separate pool and do not consume standard per-model rate limits.
- Model eligibility varies; endpoint support does not imply every model works with Batch.
- File upload endpoint: 1,000 uploads per minute per authenticated user; Batch's 200 MB constraint remains tighter than the Files API's general 512 MB maximum.

The driver should report provider rate-limit errors rather than attempting to predict a caller's current token queue allowance from hard-coded numbers.

## Proposed `OpenAiBatchProvider`

This provider adapter should be narrow and OpenAI-aware. Suggested responsibilities:

- declare the OpenAI provider identifier and the driver-enabled endpoint allowlist;
- accept already-resolved provider requests from the shared resolver rather than rebuild Laravel AI prompts, tools, schemas, or options;
- verify every resolved request is an OpenAI `POST`, uses the same enabled endpoint and model, has a JSON object body, and can be represented without per-request headers;
- turn `(custom ID, resolved request)` pairs into deterministic JSONL lines;
- enforce deterministic local file/request limits while streaming generation;
- orchestrate upload followed by create, retaining both input file ID and batch ID;
- map Batch payloads into the shared snapshot/domain shape while preserving raw status, raw provider IDs, request counts, timestamps, metadata, and provider validation errors;
- download and parse output/error files line by line, preserving endpoint response bodies and correlating by `custom_id`;
- delegate HTTP, authentication, timeouts, and raw JSON decoding to a focused OpenAI client.

It should not:

- independently translate Laravel AI agents into OpenAI messages or tools;
- own persistence or queues;
- expose OpenAI transport arrays as the package's public API;
- silently discard meaningful headers or unsupported request options;
- advertise every OpenAI Batch endpoint through an agent API before Laravel AI can resolve that endpoint correctly.

## Proposed API client responsibilities

A small internal `OpenAiBatchClient` (name illustrative) should own only provider I/O:

- `uploadBatchInput(stream, filename, expiresAfter?) -> file payload`
- `createBatch(inputFileId, endpoint, completionWindow, metadata?, outputExpiresAfter?) -> batch payload`
- `retrieveBatch(batchId) -> batch payload`
- `cancelBatch(batchId) -> batch payload`
- `retrieveFileContent(fileId) -> readable stream/string`
- optionally `listBatches(after?, limit?)` for operations/recovery
- optionally `deleteFile(fileId)` for explicit cleanup policy

Transport behavior:

- read credentials and optional organization/project/base-URL configuration from Laravel configuration; never accept secrets in per-item JSONL;
- use bearer authentication and JSON/multipart content types appropriate to each lifecycle request;
- capture OpenAI's `x-request-id` response header and send a unique `X-Client-Request-Id` for support correlation;
- decode success JSON defensively and throw a provider transport exception containing HTTP status, provider error code/type/message/param when available, request ID, and retry headers, with secrets and full prompts redacted;
- stream file upload/download where the HTTP layer permits it;
- set bounded connect/request timeouts. Polling is application-controlled; one HTTP call must not wait for batch completion.

A live credential-free request to the official API on the checked date confirmed the lifecycle HTTP error envelope used for authentication failures:

```json
{
  "error": {
    "message": "Missing bearer or basic authentication in header",
    "type": "invalid_request_error",
    "param": null,
    "code": null
  }
}
```

Treat `message`, `type`, `param`, and `code` as nullable/optional when parsing other errors, retain the HTTP status and `x-request-id` independently, and preserve an unrecognized body for diagnostics after redaction.

Retry policy should distinguish operations:

- automatically retry safe reads on connection errors, timeouts, 429, and transient 5xx with bounded exponential backoff and server retry headers;
- do not retry 400/401/403/404/422 automatically;
- cancellation is state-oriented but the endpoint is a POST; a lost response can be reconciled by retrieving the batch before retrying;
- upload and create are not documented as idempotent. If their response is lost, blindly replaying can create duplicate provider resources/work. Surface an ambiguous-submit exception with the client request ID and retained prior IDs, then reconcile via persisted state and, where useful, batch listing/metadata.

The OpenAI error guide documents common 401, 403, 429, 500, and 503 conditions and recommends retry/backoff for transient connection, timeout, rate-limit, and server errors. A provider lifecycle HTTP error is distinct from a per-request error in a completed batch and from batch-level input validation errors; keep these three channels separate in exceptions and result APIs.

## Credential-free fixtures

All tests should use Laravel HTTP fakes or a mock PSR client. No fixture may contain a usable key or make a network request. A literal such as `test-openai-key` is sufficient when asserting bearer-header construction.

Recommended fixtures:

```text
tests/Fixtures/OpenAI/
  input/responses-valid.jsonl
  input/duplicate-custom-id.jsonl
  input/mixed-models.jsonl
  input/mixed-endpoints.jsonl
  input/invalid-json.jsonl
  files/uploaded-batch-file.json
  batches/validating.json
  batches/in-progress.json
  batches/finalizing.json
  batches/completed-all-success.json
  batches/completed-partial-failure.json
  batches/failed-validation.json
  batches/expired.json
  batches/cancelling.json
  batches/cancelled-partial.json
  batches/unknown-status.json
  output/responses-success.jsonl
  output/out-of-order-success.jsonl
  errors/http-item-error.jsonl
  errors/batch-expired.jsonl
  errors/batch-cancelled.jsonl
  errors/request-timeout.jsonl
  errors/unknown-item-error.jsonl
  transport/bad-request.json
  transport/unauthorized.json
  transport/not-found.json
  transport/rate-limited.json
  transport/server-error.json
```

Fixture assertions should cover:

- multipart upload has `purpose=batch`, a `.jsonl` filename, and the exact generated bytes;
- create payload has the uploaded file ID, the resolved endpoint, and `24h`;
- each documented status maps correctly and an unknown status remains inspectable;
- a completed batch with `request_counts.failed > 0` remains batch-completed while exposing item failures;
- output order is ignored and correlation uses `custom_id`;
- response-body errors, non-HTTP item errors, validation errors, and lifecycle HTTP errors remain distinct;
- non-terminal result access reports "not ready", while terminal batches with missing output/error file IDs produce empty corresponding streams;
- malformed JSONL output reports the provider file line without leaking the full prompt;
- retry behavior is exercised without sleeping by faking the clock/backoff callback;
- logs and thrown exceptions redact `Authorization`, API keys, and request bodies by default.

## Architectural recommendations for the lead

1. Keep JSONL construction in the OpenAI provider adapter, but consume a shared immutable resolved-request object produced from Laravel AI. This prevents a second agent/request-building stack.
2. Keep the HTTP client provider-specific and internal. Its output can be typed internal payload objects or arrays, but OpenAI response shapes must not become shared public contracts.
3. Model partial success explicitly. Batch terminal state and per-item outcome are independent dimensions.
4. Treat result/error files as streams and correlate on `custom_id`; never depend on file ordering.
5. Preserve unknown statuses, error codes, and optional response fields for forward compatibility.
6. Store both `input_file_id` and provider `batch_id` as soon as each exists. Submission is a two-resource workflow and can fail between steps.
7. Do not claim all eight OpenAI endpoints in the first public release. Enable only the Laravel AI agent endpoint(s) proven by the resolver research, while keeping the internal endpoint allowlist easy to extend.
