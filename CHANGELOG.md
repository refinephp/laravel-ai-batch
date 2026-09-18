# Changelog

All notable changes to Laravel AI Batch will be documented in this file.

## [Unreleased]

## [0.2.1] - 2026-09-18

### Fixed

- Stopped `uploadBatchInput()` closing the batch input stream a second time. The HTTP client
  takes ownership of a resource passed to `attach()` and closes it when the request objects are
  released, so the `finally` block raised `TypeError: fclose(): Argument #1 ($stream) must be an
  open stream resource` wherever that release happened before the block ran. The stream is now
  closed only while it is still open, which still covers the path where the request never left.

## [0.2.0] - 2026-09-10

### Added

- Support for `laravel/ai` 0.9.0, 0.10.x, and 0.11.x alongside 0.9.1.
- A structural compatibility guard that asserts the Laravel AI request building
  signatures this package drives, replacing the exact installed-version check.
  Parameters that Laravel AI appends with defaults are tolerated; a rename,
  reorder, or newly required parameter fails with an actionable message.
- A `laravel/ai` CI job covering each supported minor version.

### Changed

- Widened the `laravel/ai` constraint from `0.9.1` to `>=0.9.0 <0.12`. 0.9.0 is the hard
  floor: earlier releases predate the step-based generation architecture the adapter hooks.

### Removed

- The `LaravelAiVersion::SUPPORTED` and `LaravelAiVersion::SUPPORTED_PRETTY` constants, and the
  `?string $installedVersion` parameter on `LaravelAiVersion::assertSupported()`. The class is
  now marked `@internal` and checks the request building signatures structurally instead of
  comparing an installed version string.

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
