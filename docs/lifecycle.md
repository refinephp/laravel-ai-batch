# Batch Lifecycle

## Submission

Submission resolves and validates every item before remote I/O. The OpenAI provider then:

1. writes bounded JSONL to a package-generated temporary path;
2. uploads it with `purpose=batch`;
3. creates a `/v1/responses` batch with a `24h` window and the local UUID in metadata;
4. maps the returned OpenAI object to an immutable snapshot;
5. deletes the local temporary file in all success and failure paths;
6. persists the snapshot through the configured repository.

Upload and create are not documented as idempotent. A connection loss during either operation throws `AmbiguousBatchSubmissionException` with safe recovery identifiers and is never automatically replayed. If OpenAI creation succeeds but local persistence fails, `BatchPersistenceException` includes the provider batch ID for operator recovery.

## Statuses

| Status | Terminal | Cancellable |
| --- | --- | --- |
| `validating` | No | Yes |
| `in_progress` | No | Yes |
| `finalizing` | No | Yes |
| `completed` | Yes | No |
| `failed` | Yes | No |
| `expired` | Yes | No |
| `cancelling` | No | No |
| `cancelled` | Yes | No |
| `unknown` | No | No |

The raw provider status is retained separately. Unknown status values do not crash hydration and are not assumed terminal.

Repository updates are monotonic. Stale snapshots cannot move a known status backward, change a remote batch ID, reduce counts, erase file IDs/timestamps, or replace an already observed terminal status.

## Polling

`ai:batch:poll` queries a deterministic page of non-terminal snapshots and dispatches `PollBatch` jobs. Each job:

1. acquires an atomic cache lock derived from a SHA-256 hash of the local UUID;
2. returns without work if another lifecycle operation owns the lock;
3. re-reads the snapshot after acquiring the lock;
4. skips remote I/O if the fresh snapshot is terminal;
5. performs one retrieval and persists the new snapshot;
6. releases the lock.

Applications opt into scheduling and operate their own queue workers. No schedule is registered automatically.

## Cancellation

The cancellation command uses the same lock and fresh-read rules as polling. Non-cancellable snapshots return unchanged, making duplicate cancellation safe.

OpenAI cancellation is asynchronous. A successful POST can return `cancelling`; keep polling. If the POST response is lost, the provider performs one safe retrieval to reconcile state and does not blindly repeat the cancellation request.

## Results

Completed, expired, and cancelled batches can expose both an output file and an error file. Both are parsed as untrusted JSONL, and results are correlated only by custom ID. Cross-file duplicates, malformed records, partial lines, invalid HTTP envelopes, and missing terminal outcomes fail explicitly.

Batch status `failed` means input validation failed. Its structured validation errors live on the batch snapshot and may have a line/parameter without a custom ID.

Result contents are lazy and are never persisted by the package. Provider output files expire; process them promptly.
