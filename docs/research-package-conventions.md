# Laravel Package Conventions Research

Checked: 2026-07-19

## Sources inspected

- [`laravel/ai` `0.x` at `a1b3ce7`](https://github.com/laravel/ai/tree/a1b3ce7437adb8bda22eb4e0308a376cd64da3d9)
- [`laravel/ai` v0.9.1 at `2760a62`](https://github.com/laravel/ai/tree/2760a62bff6ab515cdf10222f61b7973356450e1)
- [`laravel/pennant`](https://github.com/laravel/pennant)

## Confirmed compatibility baseline

Laravel AI v0.9.1 and current `0.x` both require PHP `^8.3` and Illuminate 12 or 13. They test with Orchestra Testbench `^10.6|^11.0`, Pest 3 or 4, Pint, and PHPStan 2.1. The initial package should match those platform constraints and intentionally constrain `laravel/ai` to `^0.9.1`, which means `>=0.9.1 <0.10.0`. A wider constraint would be unsafe because this package must adapt internal request-building behavior.

## Package registration

First-party packages use Composer PSR-4 autoloading and Laravel package discovery through `extra.laravel.providers`. Their service providers merge configuration in `register()`, bind contracts and managers as singletons, and limit publishing and command registration to console execution.

Laravel AI publishes configuration with tagged `publishes()` calls and migrations with `publishesMigrations()`. Laravel AI does not automatically run package migrations; Laravel AI Batch should follow the same explicit publication approach.

## Testing and quality

The current Laravel AI workflow uses Ubuntu 24.04 and a PHP 8.3/8.4/8.5 by Laravel 12/13 matrix, excluding PHP 8.5 with Laravel 12. It installs each Laravel line by constraining an Illuminate package during `composer update`. Separate workflows run tests and PHPStan. Source, configuration, and migrations are included in static analysis.

The package should use deterministic Laravel HTTP fakes, an in-memory SQLite database in Testbench, and credential-free JSON fixtures. It should expose Composer scripts for tests, formatting checks, static analysis, and the combined quality suite.

## Decisions for this package

- Require PHP `^8.3`, Laravel/Illuminate 12 or 13, and Laravel AI `^0.9.1` for version one.
- Use package discovery for `LaravelAiBatchServiceProvider` and facade alias metadata only if the alias adds value beyond explicit imports.
- Publish `config/ai-batch.php` and the package migration explicitly.
- Keep OpenAI request construction behind one compatibility adapter and cover it with payload parity tests.
- Use one tests workflow matrix plus separate coding-standards and static-analysis workflows.
- Pin GitHub Action revisions, following current first-party Laravel workflow practice.
