<?php

namespace App\Events;

use App\Models\Servers\Server;

class AcknowledgingLinkCommand
{
    public bool $handled = false;

    public function __construct(
        public readonly Server $server,
        public readonly string $id,
        public readonly string $lease,
        public readonly string $state,
        public readonly ?string $error,
    ) {
    }
}
