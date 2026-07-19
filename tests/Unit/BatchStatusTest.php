<?php

declare(strict_types=1);

use RefinePhp\LaravelAiBatch\Enums\BatchStatus;

it('models terminal successful and cancellable states', function (): void {
    expect(BatchStatus::Completed->isTerminal())->toBeTrue()
        ->and(BatchStatus::Completed->isSuccessful())->toBeTrue()
        ->and(BatchStatus::Failed->isTerminal())->toBeTrue()
        ->and(BatchStatus::Expired->isTerminal())->toBeTrue()
        ->and(BatchStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(BatchStatus::InProgress->isTerminal())->toBeFalse()
        ->and(BatchStatus::Unknown->isTerminal())->toBeFalse()
        ->and(BatchStatus::Validating->canBeCancelled())->toBeTrue()
        ->and(BatchStatus::Finalizing->canBeCancelled())->toBeTrue()
        ->and(BatchStatus::Cancelling->canBeCancelled())->toBeFalse();
});
