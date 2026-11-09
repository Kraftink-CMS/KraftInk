<?php

namespace KraftInk\Games\RedM\Providers;

use App\Kraft\Games\Transports\FiveMRconTransport;
use App\Kraft\Games\Transports\FiveMStatusTransport;
use Illuminate\Support\ServiceProvider;
use KraftInk\Games\RedM\Adapters\GameAdapter;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'games.adapters.redm' => GameAdapter::class,
            'games.transports.redm.http' => FiveMStatusTransport::class,
            'games.transports.redm.fxserver_rcon' => FiveMRconTransport::class,
        ]);
    }
}
