<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Facades;

use Illuminate\Support\Facades\Facade;
use RefinePhp\LaravelAiBatch\BatchManager;

/** @see BatchManager */
final class AiBatch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BatchManager::class;
    }
}
