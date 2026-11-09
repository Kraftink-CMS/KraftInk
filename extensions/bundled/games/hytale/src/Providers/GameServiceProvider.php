<?php

namespace KraftInk\Games\Hytale\Providers;

use Illuminate\Support\ServiceProvider;
use KraftInk\Games\Hytale\Adapters\HytaleAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->registerUrl(
            'hytale',
            'crafthead',
            [
                'name' => 'Crafthead',
                'description' => 'hytale::avatars.crafthead',
                'website' => 'https://crafthead.net/',
            ],
            'https://crafthead.net/hytale/avatar/{identity}/128',
            usernamePattern: '/^[A-Za-z0-9_]{3,16}$/D',
            default: true,
        );
        config(['games.adapters.hytale' => HytaleAdapter::class]);
    }
}
