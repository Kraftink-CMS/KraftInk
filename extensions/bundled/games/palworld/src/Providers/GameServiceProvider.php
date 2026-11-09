<?php

namespace KraftInk\Games\Palworld\Providers;

use Illuminate\Support\ServiceProvider;
use KraftInk\Games\Palworld\Adapters\GameAdapter;
use KraftInk\Games\Palworld\Transports\RestTransport;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'games.adapters.palworld' => GameAdapter::class,
            'games.transports.palworld.rest' => RestTransport::class,
        ]);
    }
}
