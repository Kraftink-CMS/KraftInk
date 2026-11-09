<?php

namespace App\Kraft\System;

use Closure;

class OperationLease
{
    private bool $released = false;

    public function __construct(private readonly Closure $release)
    {
    }

    public function release(): void
    {
        if (!$this->released) {
            $this->released = true;
            ($this->release)();
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
