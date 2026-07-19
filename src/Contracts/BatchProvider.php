<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Contracts;

use RefinePhp\LaravelAiBatch\Data\BatchError;
use RefinePhp\LaravelAiBatch\Data\BatchResult;
use RefinePhp\LaravelAiBatch\Data\BatchSubmission;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;

interface BatchProvider
{
    public function name(): string;

    public function submit(BatchSubmission $submission): ProviderBatch;

    public function refresh(ProviderBatch $batch): ProviderBatch;

    public function cancel(ProviderBatch $batch): ProviderBatch;

    /** @return iterable<int, BatchResult> */
    public function results(ProviderBatch $batch): iterable;

    /** @return iterable<int, BatchError> */
    public function errors(ProviderBatch $batch): iterable;
}
