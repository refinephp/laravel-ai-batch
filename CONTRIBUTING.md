# Contributing

Thank you for considering a contribution to Laravel AI Batch.

1. Open an issue before beginning a public API or compatibility change.
2. Install dependencies with `composer install`.
3. Run `composer quality` before submitting a pull request.
4. Add tests for behavioral changes and never include real credentials or sensitive prompts in fixtures.

Compatibility changes require signature coverage and payload-parity tests against the exact supported `laravel/ai` release.

## Releasing

The `Release` GitHub Actions workflow publishes stable semantic-version tags from `main`. Before running it:

1. Add a matching version heading to `CHANGELOG.md`.
2. Configure the `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` repository secrets. A Packagist safe API token is sufficient.
3. Open **Actions → Release → Run workflow**, leave `main` selected, and enter a tag such as `v1.2.3`. Do not enter a branch name in the version field.

The workflow validates the package, runs the complete quality suite, creates the GitHub tag and release, requests a Packagist refresh, and verifies that the version is available.
