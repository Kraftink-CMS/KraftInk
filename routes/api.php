<?php

use App\Http\Controllers\Auth\AuthApiController;
use App\Http\Controllers\Link\LinkAccountController;
use App\Http\Controllers\Link\LinkController;
use App\Http\Controllers\Link\LinkInGameSignInController;
use App\Http\Controllers\Link\LinkPollController;
use App\Http\Controllers\Link\LinkRequestController;
use App\Http\Controllers\Api\PublicDataController;
use App\Http\Middleware\ApiAvailability;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateLink;
use App\Http\Middleware\LinkAccountsAvailable;
use App\Http\Middleware\SystemContext;
use App\Http\Middleware\VerifyCaptcha;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    Route::prefix('site')
        ->name('site.')
        ->middleware([ApiAvailability::class, 'throttle:90,1'])
        ->group(function (): void {
            Route::prefix('news')
                ->name('news.')
                ->middleware([AuthenticateApiToken::class.':site:news:read', 'permission:content.manage'])
                ->group(function (): void {
                    Route::get('/', [PublicDataController::class, 'news'])->name('index');
                    Route::get('/{id}', [PublicDataController::class, 'show'])
                        ->whereNumber('id')
                        ->name('show');
                });
            Route::get('/servers', [PublicDataController::class, 'servers'])
                ->middleware([AuthenticateApiToken::class.':site:servers:read', 'permission:servers.manage'])
                ->name('servers.index');
        });
    app(RateLimiter::class)->for('auth-api', fn (Request $request) => Limit::perMinute(90)->by($request->ip()));
    app(RateLimiter::class)->for('auth-api-login', function (Request $request): array {
        $identifier = $request->input($request->routeIs('api.auth.challenge') ? 'challenge' : 'login');
        $key = is_string($identifier) ? mb_strtolower(trim($identifier)) : '';

        return [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by(hash('sha256', $key).'|'.$request->ip()),
        ];
    });
    app(RateLimiter::class)->for(
        'link-accounts',
        fn (Request $request) => [
            Limit::perMinute(60)->by(hash('sha256', (string) $request->bearerToken())),
            Limit::perMinute(120)->by($request->ip()),
        ],
    );
    Route::name('auth.')
        ->prefix('auth')
        ->middleware([ApiAvailability::class, 'throttle:auth-api'])
        ->group(function () {
            Route::post('/authenticate', [AuthApiController::class, 'authenticate'])
                ->middleware(['throttle:auth-api-login', VerifyCaptcha::class.':login'])
                ->name('authenticate');
            Route::post('/challenge', [AuthApiController::class, 'challenge'])
                ->middleware('throttle:auth-api-login')
                ->name('challenge');
            Route::post('/verify', [AuthApiController::class, 'verify'])
                ->middleware(AuthenticateApiToken::class.':profile:read')
                ->name('verify');
            Route::get('/me', [AuthApiController::class, 'verify'])
                ->middleware(AuthenticateApiToken::class.':profile:read')
                ->name('me');
            Route::get('/token', [AuthApiController::class, 'token'])
                ->middleware(AuthenticateApiToken::class)
                ->name('token');
            Route::get('/games', [AuthApiController::class, 'games'])
                ->middleware(AuthenticateApiToken::class.':games:read')
                ->name('games.index');
            Route::post('/rotate', [AuthApiController::class, 'rotate'])
                ->middleware(AuthenticateApiToken::class)
                ->name('rotate');
            Route::post('/logout', [AuthApiController::class, 'logout'])
                ->middleware(AuthenticateApiToken::class)
                ->name('logout');
        });
    Route::name('link.')
        ->prefix('link')
        ->group(function () {
            Route::post('/request', LinkRequestController::class)
                ->middleware([AuthenticateLink::class, 'throttle:link-request'])
                ->name('request');
            Route::post('/poll', LinkPollController::class)
                ->middleware([AuthenticateLink::class, 'maintenance.bypass', 'throttle:link-poll'])
                ->name('poll');
            Route::post('/heartbeat', [LinkController::class, 'heartbeat'])
                ->middleware([AuthenticateLink::class, 'maintenance.bypass', 'throttle:120,1'])
                ->name('heartbeat.store');
            Route::post('/in-game-sign-in/respond', [LinkInGameSignInController::class, 'respond'])
                ->middleware([AuthenticateLink::class, 'throttle:120,1,in-game-sign-in-connector-'])
                ->name('in-game-sign-in.respond');
            Route::name('commands.')
                ->prefix('commands')
                ->middleware([AuthenticateLink::class, 'maintenance.bypass', 'throttle:120,1'])
                ->group(function () {
                    Route::post('/claim', [LinkController::class, 'claim'])->name('claim');
                    Route::post('/{id}/ack', [LinkController::class, 'acknowledge'])
                        ->where('id', '[a-z]+:[a-fA-F0-9-]+')
                        ->name('acknowledge');
                });
            Route::name('accounts.')
                ->prefix('accounts')
                ->middleware([LinkAccountsAvailable::class, 'throttle:link-accounts'])
                ->group(function () {
                    Route::get('/policy', [LinkAccountController::class, 'policy'])->name('policy');
                    Route::post('/register', [LinkAccountController::class, 'register'])->name('register');
                    Route::get('/players/{uuid}', [LinkAccountController::class, 'profile'])->name('profile');
                    Route::get('/players/{uuid}/balance', [LinkAccountController::class, 'balance'])->name('balance');
                    Route::post('/players/{uuid}/email', [LinkAccountController::class, 'email'])->name('email');
                    Route::post('/players/{uuid}/password', [LinkAccountController::class, 'password'])->name(
                        'password',
                    );
                    Route::post('/players/{uuid}/balance/{kind}', [LinkAccountController::class, 'adjust'])
                        ->where('kind', 'credit|debit|set')
                        ->name('adjust');
                });
        });
    Route::name('public.')
        ->prefix('public')
        ->middleware([SystemContext::class, 'throttle:120,1'])
        ->group(function () {
            Route::name('news.')
                ->prefix('news')
                ->group(function () {
                    Route::get('/', [PublicDataController::class, 'news'])->name('index');
                    Route::get('/{id}', [PublicDataController::class, 'show'])
                        ->whereNumber('id')
                        ->name('show');
                });
            Route::get('/servers', [PublicDataController::class, 'servers'])->name('servers.index');
        });
});
