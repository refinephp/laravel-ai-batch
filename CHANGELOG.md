# Changelog

All notable changes to Laravel AI Batch will be documented in this file.

## [Unreleased]

## [0.1.2] - 2026-07-21

### Fixed

- Made manual releases fail clearly when dispatched from a tag instead of `main`.
- Prevented stale Packagist responses from causing false release verification failures.

## [0.1.1] - 2026-07-21

### Added

- Dispatched a `BatchStatusUpdated` event when a persisted batch status changes.

## [0.1.0] - 2026-07-20

### Added

- Exact initial-request resolution for native OpenAI agents on `laravel/ai` 0.9.1.
- OpenAI Responses Batch JSONL generation, upload, creation, refresh, cancellation, and result retrieval.
- Immutable batch, request, result, and error data objects with custom-ID correlation.
- Optional Eloquent lifecycle persistence with monotonic concurrent updates.
- Lock-aware polling and cancellation services, queued polling job, and Artisan commands.
- Publishable configuration and migration, package discovery, facade, and dependency-injection bindings.
- Compatibility, lifecycle, architecture, provider-extension, security, and usage documentation.
- Pest, PHPStan, Pint, and a Laravel/PHP GitHub Actions matrix.
