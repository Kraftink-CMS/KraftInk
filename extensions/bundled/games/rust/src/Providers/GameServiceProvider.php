<?php

namespace KraftInk\Games\Rust\Providers;

use Illuminate\Support\ServiceProvider;
use App\Kraft\Games\Transports\WebRconTransport;
use App\Kraft\Games\Transports\SourceQueryTransport;
use KraftInk\Games\Rust\Adapters\GameAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('rust', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.rust' => GameAdapter::class,
            'games.transports.rust.query' => SourceQueryTransport::class,
            'games.transports.rust.webrcon' => WebRconTransport::class,
        ]);
    }
}
