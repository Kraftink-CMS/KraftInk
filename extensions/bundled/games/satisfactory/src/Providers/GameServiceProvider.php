<?php

namespace KraftInk\Games\Satisfactory\Providers;

use Illuminate\Support\ServiceProvider;
use KraftInk\Games\Satisfactory\Adapters\GameAdapter;
use KraftInk\Games\Satisfactory\Transports\HttpsTransport;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'games.adapters.satisfactory' => GameAdapter::class,
            'games.transports.satisfactory.https_api' => HttpsTransport::class,
        ]);
    }
}
