<?php

namespace KraftInk\Games\ConanExiles\Providers;

use Illuminate\Support\ServiceProvider;
use KraftInk\Games\ConanExiles\Adapters\GameAdapter;
use KraftInk\Games\ConanExiles\Transports\QueryTransport;
use KraftInk\Games\ConanExiles\Transports\RconTransport;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'games.adapters.conanexiles' => GameAdapter::class,
            'games.transports.conanexiles.query' => QueryTransport::class,
            'games.transports.conanexiles.rcon' => RconTransport::class,
        ]);
    }
}
