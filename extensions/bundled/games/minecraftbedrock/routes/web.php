<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Games\MinecraftBedrock\Http\Controllers\IdentityController;

Route::prefix('account/games/bedrock/verify')
    ->name('games.minecraftbedrock.identity.')
    ->middleware(['web', 'auth', 'throttle:15,1,bedrock-identity'])
    ->group(function () {
        Route::get('/', [IdentityController::class, 'index'])->name('index');
        Route::post('/', [IdentityController::class, 'start'])->block()->name('start');
        Route::post('/confirm', [IdentityController::class, 'confirm'])->block()->name('confirm');
    });
