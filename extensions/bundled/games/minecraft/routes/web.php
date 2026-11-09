<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Games\Minecraft\Http\Controllers\BedrockVerificationController;
use KraftInk\Games\Minecraft\Http\Controllers\ProfileController;

Route::prefix('minecraft/profile')
    ->name('games.minecraft.public.profile.')
    ->middleware(['web', 'guest', 'throttle:auth'])
    ->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('edit');
        Route::post('/', [ProfileController::class, 'store'])->name('store');
    });

Route::prefix('minecraft/bedrock/verify')
    ->name('games.minecraft.public.bedrock.')
    ->middleware(['web', 'guest'])
    ->group(function () {
        Route::get('/', [BedrockVerificationController::class, 'show'])->block()->name('show');
        Route::post('/', [BedrockVerificationController::class, 'start'])
            ->block()
            ->middleware('throttle:minecraft-bedrock')
            ->name('start');
        Route::post('/complete', [BedrockVerificationController::class, 'complete'])
            ->block()
            ->middleware('throttle:minecraft-bedrock')
            ->name('complete');
    });
