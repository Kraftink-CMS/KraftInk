<?php

namespace App\Kraft;

use App\Kraft\Games\GameRegistry;
use App\Models\Servers\Server;

class CommunityConnection
{
    public function __construct(private Settings $settings, private GameRegistry $games)
    {
    }

    public function data(): array
    {
        $primary = $this->settings->get('servers.primary');
        $server = $primary
            ? Server::query()
                ->whereKey($primary)
                ->where('game', $this->games->activeId())
                ->first(['host', 'port', 'show_port', 'show_players', 'enabled', 'status', 'last_seen_at'])
            : null;

        return [
            'address' => $server?->publicAddress(),
            'players_online' => $server?->show_players ? $server->onlinePlayerCount() : null,
            'players_max' => $server?->show_players ? $server->playerCapacity() : null,
        ];
    }
}
