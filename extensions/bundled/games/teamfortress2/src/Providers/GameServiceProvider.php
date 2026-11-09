<?php

namespace KraftInk\Games\TeamFortress2\Providers;

use Illuminate\Support\ServiceProvider;
use App\Kraft\Games\Transports\SourceRconTransport;
use App\Kraft\Games\Transports\SourceQueryTransport;
use KraftInk\Games\TeamFortress2\Adapters\GameAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('teamfortress2', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.teamfortress2' => GameAdapter::class,
            'games.transports.teamfortress2.query' => SourceQueryTransport::class,
            'games.transports.teamfortress2.source_rcon' => SourceRconTransport::class,
        ]);
    }
}
