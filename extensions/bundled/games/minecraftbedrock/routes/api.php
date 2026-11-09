<?php

use App\Http\Middleware\AuthenticateLink;
use Illuminate\Support\Facades\Route;
use KraftInk\Games\MinecraftBedrock\Http\Controllers\IdentityLinkController;

Route::post('/api/link/player-identity/verify', IdentityLinkController::class)
    ->middleware(['api', AuthenticateLink::class, 'throttle:60,1,player-identity-verification'])
    ->name('games.minecraftbedrock.identity.verify');
