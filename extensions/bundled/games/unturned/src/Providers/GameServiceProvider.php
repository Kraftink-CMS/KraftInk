<?php

namespace KraftInk\Games\Unturned\Providers;

use Illuminate\Support\ServiceProvider;
use App\Kraft\Games\Transports\SourceRconTransport;
use App\Kraft\Games\Transports\SourceQueryTransport;
use KraftInk\Games\Unturned\Adapters\GameAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('unturned', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.unturned' => GameAdapter::class,
            'games.transports.unturned.query' => SourceQueryTransport::class,
            'games.transports.unturned.source_rcon' => SourceRconTransport::class,
        ]);
    }
}
