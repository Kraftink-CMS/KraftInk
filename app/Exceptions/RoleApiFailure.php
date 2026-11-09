<?php

namespace App\Exceptions;

use RuntimeException;

class RoleApiFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $retryAfter = 60,
        public readonly bool $requiresAuthorization = false,
    ) {
        parent::__construct($reason);
    }
}
