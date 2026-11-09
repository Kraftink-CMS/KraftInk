<?php

namespace App\Events;

use App\Models\Servers\Server;
use Illuminate\Support\Collection;

class CollectingLinkCommands
{
    public function __construct(
        public readonly Server $server,
        public readonly array $players,
        public readonly int $limit,
        public Collection $commands,
        public readonly array $playerNames = [],
    ) {
    }
}
