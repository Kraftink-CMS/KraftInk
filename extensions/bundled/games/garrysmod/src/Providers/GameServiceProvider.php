<?php

namespace KraftInk\Games\GarrysMod\Providers;

use Illuminate\Support\ServiceProvider;
use App\Kraft\Games\Transports\SourceRconTransport;
use App\Kraft\Games\Transports\SourceQueryTransport;
use KraftInk\Games\GarrysMod\Adapters\GameAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('garrysmod', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.garrysmod' => GameAdapter::class,
            'games.transports.garrysmod.query' => SourceQueryTransport::class,
            'games.transports.garrysmod.source_rcon' => SourceRconTransport::class,
        ]);
    }
}
