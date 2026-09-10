<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Tests\Fixtures\Compatibility;

/**
 * Signature shapes used to drive the structural Laravel AI compatibility guard.
 *
 * The guard is asserted against these instead of the real Laravel AI classes,
 * whose signatures cannot be varied from a test.
 */
final class SignatureShapes
{
    public function expected(string $provider, string $model): void {}

    public function appendedOptional(string $provider, string $model, ?string $added = null): void {}

    public function appendedRequired(string $provider, string $model, string $added): void {}

    public function renamed(string $provider, string $modelName): void {}

    public function reordered(string $model, string $provider): void {}

    public function truncated(string $provider): void {}
}
