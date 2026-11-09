<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Lang;
use RuntimeException;
use Throwable;

class TransportFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $terminal = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }

    public static function label(?string $reason): string
    {
        if ($reason !== null && str_starts_with($reason, 'transports.')) {
            $key = 'admin.servers.transport.'.substr($reason, strlen('transports.'));
        } elseif ($reason !== null && str_starts_with($reason, 'admin.servers.transport.')) {
            $key = $reason;
        } else {
            $key = 'admin.servers.transport.unavailable';
        }

        $label = Lang::has($key) ? __($key) : null;

        return is_string($label) ? $label : __('admin.servers.transport.unavailable');
    }
}
