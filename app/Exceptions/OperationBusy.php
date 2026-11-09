<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationBusy extends HttpException
{
    public function __construct()
    {
        parent::__construct(503, __('system.coordination.busy'), headers: ['Retry-After' => '5']);
    }
}
