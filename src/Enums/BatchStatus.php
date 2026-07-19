<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Enums;

enum BatchStatus: string
{
    case Validating = 'validating';
    case InProgress = 'in_progress';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Expired, self::Cancelled => true,
            default => false,
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }

    public function canBeCancelled(): bool
    {
        return match ($this) {
            self::Validating, self::InProgress, self::Finalizing => true,
            default => false,
        };
    }
}
