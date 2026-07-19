<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Console\Commands;

use Illuminate\Console\Command;

abstract class BatchCommand extends Command
{
    protected function safe(string $value, int $limit = 200): string
    {
        $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';

        return substr($value, 0, $limit);
    }
}
