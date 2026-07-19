# Extending Providers

Version one intentionally ships only a native OpenAI provider. This document is an architectural boundary, not a claim that another provider can be added by configuration alone.

## Research first

Before implementing another provider, document:

- its official asynchronous batch lifecycle and supported request endpoints;
- file or request envelope format, limits, retention, and status values;
- cancellation, expiration, partial-result, and idempotency behavior;
- whether Laravel AI can resolve an exact request for that provider without sending;
- how provider-native output maps back to caller custom IDs;
- credentials and sensitive-data boundaries;
- deterministic fixtures based on official response shapes.

Do not force an unstudied provider into OpenAI terminology or add capability flags for hypothetical behavior.

## Stable package contracts

A researched provider implements `Contracts\BatchProvider` and consumes `BatchSubmission` containing package-owned `BatchRequest` and `ResolvedProviderRequest` objects. It returns package-owned `ProviderBatch`, `BatchResult`, and `BatchError` data.

Provider code owns transport, validation, status mapping, and output parsing. It must not own repositories, queues, schedules, or application result handlers.

If the new lifecycle cannot honestly implement the existing contract, propose a deliberate public-contract revision rather than silently approximating behavior.

## Request resolution

Provider request resolution belongs behind `Contracts\RequestResolver`. Laravel AI internal classes must remain inside a versioned compatibility adapter and must never appear in public constructors, return values, exceptions, or persisted records.

A new adapter needs exact payload-parity tests and a no-HTTP proof. If exact resolution is impossible, fail specifically and document the upstream API needed; do not build a second request DSL and call it equivalent.

## Required tests

At minimum, add tests for request parity, envelope serialization, every status, submission, refresh, cancellation, partial and out-of-order results, duplicate/missing/malformed results, transport/auth/rate-limit/timeouts, ambiguous side effects, safe cleanup, locking, and sensitive-data redaction.
