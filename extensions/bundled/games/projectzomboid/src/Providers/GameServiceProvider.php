<?php

namespace KraftInk\Games\ProjectZomboid\Providers;

use Illuminate\Support\ServiceProvider;
use App\Kraft\Games\Transports\SourceRconTransport;
use App\Kraft\Games\Transports\SourceQueryTransport;
use KraftInk\Games\ProjectZomboid\Adapters\GameAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('projectzomboid', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.projectzomboid' => GameAdapter::class,
            'games.transports.projectzomboid.query' => SourceQueryTransport::class,
            'games.transports.projectzomboid.source_rcon' => SourceRconTransport::class,
        ]);
    }
}
