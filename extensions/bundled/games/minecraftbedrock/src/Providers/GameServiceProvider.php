<?php

namespace KraftInk\Games\MinecraftBedrock\Providers;

use Illuminate\Support\ServiceProvider;
use KraftInk\Games\MinecraftBedrock\Adapters\BedrockAdapter;
use KraftInk\Games\MinecraftBedrock\Transports\StatusTransport;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'games.adapters.minecraftbedrock' => BedrockAdapter::class,
            'games.transports.minecraftbedrock.ping' => StatusTransport::class,
            'games.link_platforms.minecraftbedrock' => ['nukkit'],
        ]);
    }
}
