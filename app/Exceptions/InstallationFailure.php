<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class InstallationFailure extends RuntimeException
{
    public function __construct(public readonly string $translationKey, ?Throwable $previous = null)
    {
        parent::__construct(__($translationKey), 0, $previous);
    }
}
