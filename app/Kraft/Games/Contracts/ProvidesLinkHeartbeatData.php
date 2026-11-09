<?php

namespace App\Kraft\Games\Contracts;

use App\Models\Servers\Server;

interface ProvidesLinkHeartbeatData
{
    public function linkHeartbeatData(Server $server): array;
}
