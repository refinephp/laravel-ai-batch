<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Exceptions;

use RefinePhp\LaravelAiBatch\Contracts\BatchThrowable;
use RuntimeException;

class BatchException extends RuntimeException implements BatchThrowable {}
