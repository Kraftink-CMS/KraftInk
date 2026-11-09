<?php

namespace KraftInk\Games\ArkAscended\Providers;

use Illuminate\Support\ServiceProvider;
use KraftInk\Games\ArkAscended\Adapters\GameAdapter;
use KraftInk\Games\ArkAscended\Transports\RconTransport;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'games.adapters.arkascended' => GameAdapter::class,
            'games.transports.arkascended.rcon' => RconTransport::class,
        ]);
    }
}
