<?php

namespace KraftInk\Games\CounterStrike2\Providers;

use Illuminate\Support\ServiceProvider;
use App\Kraft\Games\Transports\SourceRconTransport;
use App\Kraft\Games\Transports\SourceQueryTransport;
use KraftInk\Games\CounterStrike2\Adapters\GameAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('counterstrike2', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.counterstrike2' => GameAdapter::class,
            'games.transports.counterstrike2.query' => SourceQueryTransport::class,
            'games.transports.counterstrike2.source_rcon' => SourceRconTransport::class,
        ]);
    }
}
