<?php

use App\Http\Middleware\AuthenticateLink;
use Illuminate\Support\Facades\Route;
use KraftInk\Games\Minecraft\Http\Controllers\AuthMeRegistrationController;
use KraftInk\Games\Minecraft\Http\Controllers\BedrockLinkController;

Route::post('/api/link/bedrock/verify', [BedrockLinkController::class, 'verify'])
    ->middleware(['api', AuthenticateLink::class, 'throttle:60,1,bedrock-verification'])
    ->name('api.link.bedrock.verify');

Route::post('/api/link/authme/registration', AuthMeRegistrationController::class)
    ->middleware(['api', AuthenticateLink::class, 'throttle:30,1,authme-registration'])
    ->name('api.link.authme.registration');
