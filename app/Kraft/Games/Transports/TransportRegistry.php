<?php

namespace App\Kraft\Games\Transports;

use App\Models\Servers\Server;

class TransportRegistry
{
    public function get(Server $server): ?Transport
    {
        $class = config('games.transports.'.$server->game.'.'.$server->connection);

        return is_string($class) && is_subclass_of($class, Transport::class) ? app($class) : null;
    }

    public function metadata(string $game): array
    {
        $items = [];
        foreach (config('games.transports.'.$game, []) as $id => $class) {
            if (is_subclass_of($class, Transport::class)) {
                $transport = app($class);
                $items[$id] = [
                    ...$transport->metadata(),
                    'player_presence' => $transport instanceof ProvidesOnlinePlayers,
                ];
            }
        }

        return $items;
    }

    public function requiresSetup(Server $server): bool
    {
        if ($server->connection === 'kraftlink') {
            return !$server->token_hash;
        }

        return !empty($this->get($server)?->metadata()['secret']) && blank($server->secret);
    }
}
