<?php

namespace App\Exceptions;

use RuntimeException;

class MarketRequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }
}
