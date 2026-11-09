<?php

namespace App\Kraft\Link\Contracts;

use App\Models\Servers\Server;

interface PollHandler
{
    public function poll(Server $server, array $payload): array;
}
