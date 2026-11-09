<?php

namespace KraftInk\Games\DayZ\Providers;

use Illuminate\Support\ServiceProvider;
use App\Kraft\Games\Transports\BattlEyeTransport;
use App\Kraft\Games\Transports\SourceQueryTransport;
use KraftInk\Games\DayZ\Adapters\GameAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('dayz', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.dayz' => GameAdapter::class,
            'games.transports.dayz.query' => SourceQueryTransport::class,
            'games.transports.dayz.battleye' => BattlEyeTransport::class,
        ]);
    }
}
