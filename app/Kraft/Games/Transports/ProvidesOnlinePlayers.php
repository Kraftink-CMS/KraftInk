<?php

namespace App\Kraft\Games\Transports;

use App\Models\Servers\Server;

interface ProvidesOnlinePlayers
{
    public function onlinePlayers(Server $server): array;
}
