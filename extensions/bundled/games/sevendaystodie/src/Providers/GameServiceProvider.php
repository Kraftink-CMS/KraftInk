<?php

namespace KraftInk\Games\SevenDaysToDie\Providers;

use Illuminate\Support\ServiceProvider;
use App\Kraft\Games\Transports\TelnetTransport;
use App\Kraft\Games\Transports\SourceQueryTransport;
use KraftInk\Games\SevenDaysToDie\Adapters\GameAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('sevendaystodie', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.sevendaystodie' => GameAdapter::class,
            'games.transports.sevendaystodie.query' => SourceQueryTransport::class,
            'games.transports.sevendaystodie.telnet' => TelnetTransport::class,
        ]);
    }
}
