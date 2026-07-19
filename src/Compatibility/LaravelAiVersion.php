<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Compatibility;

use Composer\InstalledVersions;
use RefinePhp\LaravelAiBatch\Exceptions\UnsupportedLaravelAiVersionException;

final class LaravelAiVersion
{
    public const SUPPORTED = '0.9.1.0';

    public const SUPPORTED_PRETTY = '0.9.1';

    public static function assertSupported(?string $installedVersion = null): void
    {
        $installedVersion ??= InstalledVersions::getVersion('laravel/ai');

        if ($installedVersion === self::SUPPORTED) {
            return;
        }

        throw new UnsupportedLaravelAiVersionException(sprintf(
            'Laravel AI version [%s] is unsupported; this request resolver requires exactly version [%s].',
            $installedVersion ?? 'unknown',
            self::SUPPORTED_PRETTY,
        ));
    }
}
