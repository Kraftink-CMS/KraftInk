<?php

namespace App\Kraft\Link\Contracts;

use App\Models\Servers\Server;

interface RequestHandler
{
    public function handle(Server $server, array $payload): array;
}
