<?php

namespace KraftInk\Games\Ark\Providers;

use Illuminate\Support\ServiceProvider;
use App\Kraft\Games\Transports\SourceRconTransport;
use App\Kraft\Games\Transports\SourceQueryTransport;
use KraftInk\Games\Ark\Adapters\GameAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('ark', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.ark' => GameAdapter::class,
            'games.transports.ark.query' => SourceQueryTransport::class,
            'games.transports.ark.source_rcon' => SourceRconTransport::class,
        ]);
    }
}
