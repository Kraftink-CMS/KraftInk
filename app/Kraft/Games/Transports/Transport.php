<?php

namespace App\Kraft\Games\Transports;

use App\Models\Servers\Server;
use Closure;

interface Transport
{
    public function metadata(): array;

    public function status(Server $server): array;

    public function execute(Server $server, string $command, Closure $writing): void;
}
