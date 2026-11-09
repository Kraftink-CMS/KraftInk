<?php

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Site\AppearanceController;
use App\Http\Controllers\Auth\AuthApiController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\InGameSignInController;
use App\Http\Controllers\Auth\RegistrationCompletionController;
use App\Http\Controllers\Account\AvatarController;
use App\Http\Controllers\Content\ContentCommentController;
use App\Http\Controllers\Content\ContentController;
use App\Http\Controllers\Content\ContentCoverController;
use App\Http\Controllers\Content\ContentImageController;
use App\Http\Controllers\Account\DiscordRoleController;
use App\Http\Controllers\Extensions\ExtensionAssetController;
use App\Http\Controllers\Games\GameController;
use App\Http\Controllers\Installation\InstallController;
use App\Http\Controllers\Site\LocalDateController;
use App\Http\Controllers\Content\MediaController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Account\PrivacyController;
use App\Http\Controllers\Site\PublicController;
use App\Http\Controllers\Site\PublicFeedController;
use App\Http\Controllers\Site\RedirectController;
use App\Http\Controllers\Site\SiteManifestController;
use App\Http\Controllers\Account\WalletController;
use App\Http\Middleware\KeepRequiredTwoFactor;
use App\Http\Middleware\VerifyCaptcha;
use App\Kraft\Auth\LoginManager;
use App\Kraft\Auth\AuthForms;
use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Notifications\NotificationPreferencesController;
use App\Http\Middleware\ApiAvailability;

Route::name('account.')
    ->middleware('auth')
    ->group(function () {
        Route::name('wallet.')
            ->prefix('account/wallet')
            ->group(function () {
                Route::get('/', [WalletController::class, 'account'])->name('index');
                Route::post('/transfer', [WalletController::class, 'transfer'])
                    ->middleware('throttle:sensitive')
                    ->name('transfer');
            });
        Route::get('/account', [AuthController::class, 'account'])->name('index');
        Route::post('/account/profile', [AuthController::class, 'profile'])->name('profile.update');
        Route::post('/account/password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:sensitive')
            ->name('password.update');
        Route::name('two_factor.')
            ->prefix('account/two-factor')
            ->middleware('throttle:sensitive')
            ->group(function () {
                Route::post('/', [AuthController::class, 'setupTwoFactor'])->name('setup');
                Route::post('/confirm', [AuthController::class, 'confirmTwoFactor'])->name('confirm');
                Route::delete('/', [AuthController::class, 'disableTwoFactor'])
                    ->middleware(KeepRequiredTwoFactor::class)
                    ->name('destroy');
            });
        Route::name('connections.')
            ->prefix('account/connections')
            ->middleware(['capability:canManageConnections', 'throttle:sensitive'])
            ->group(function () {
                Route::get('/', [OAuthController::class, 'connections'])->name('index');
                Route::post('/{provider}', [OAuthController::class, 'link'])->name('store');
                Route::delete('/{provider}', [OAuthController::class, 'unlink'])->name('destroy');
            });
        Route::name('avatar.')
            ->prefix('account/avatar')
            ->group(function () {
                Route::post('/', [AvatarController::class, 'store'])
                    ->middleware('throttle:5,1')
                    ->name('store');
                Route::delete('/', [AvatarController::class, 'destroy'])
                    ->middleware('throttle:sensitive')
                    ->name('destroy');
            });
        Route::name('notifications.')
            ->prefix('notifications')
            ->group(function () {
                Route::get('/', [NotificationController::class, 'index'])->name('index');
                Route::get('/preferences', [NotificationPreferencesController::class, 'edit'])->name(
                    'preferences.edit',
                );
                Route::post('/preferences', [NotificationPreferencesController::class, 'update'])->name(
                    'preferences.update',
                );
                Route::post('/read-all', [NotificationController::class, 'readAll'])
                    ->middleware('throttle:20,1')
                    ->name('read_all');
                Route::post('/{notice}/read', [NotificationController::class, 'read'])->name('read');
            });
        Route::name('games.')
            ->prefix('account/games')
            ->middleware('capability:canManageGames')
            ->group(function () {
                Route::get('/', [GameController::class, 'account'])->name('index');
                Route::post('/{game}', [GameController::class, 'identity'])
                    ->middleware('throttle:sensitive')
                    ->name('identity');
            });
        Route::name('privacy.')
            ->prefix('account/privacy')
            ->group(function () {
                Route::get('/', [PrivacyController::class, 'index'])->name('index');
                Route::post('/export', [PrivacyController::class, 'export'])
                    ->middleware('throttle:sensitive')
                    ->name('export');
                Route::delete('/', [PrivacyController::class, 'destroy'])
                    ->middleware('throttle:sensitive')
                    ->name('destroy');
            });
        Route::name('sessions.')
            ->prefix('account/sessions')
            ->middleware('throttle:sensitive')
            ->group(function () {
                Route::post('/revoke', [PrivacyController::class, 'revokeOthers'])->name('revoke');
                Route::delete('/{session}', [PrivacyController::class, 'revokeSession'])->name('destroy');
            });
        Route::name('tokens.')
            ->prefix('account/tokens')
            ->middleware('capability:canManageTokens')
            ->group(function () {
                Route::get('/', [AuthApiController::class, 'account'])->name('index');
                Route::post('/', [AuthApiController::class, 'store'])
                    ->middleware([ApiAvailability::class, 'throttle:sensitive'])
                    ->name('store');
                Route::delete('/', [AuthApiController::class, 'revoke'])
                    ->middleware('throttle:sensitive')
                    ->name('revoke');
            });
        Route::name('discord.')
            ->prefix('account/discord-roles')
            ->group(function () {
                Route::name('roles.')->group(function () {
                    Route::get('/', [DiscordRoleController::class, 'index'])
                        ->middleware('capability:canManageDiscordRoles')
                        ->name('index');
                    Route::post('/', [DiscordRoleController::class, 'start'])
                        ->middleware('throttle:sensitive')
                        ->name('start');
                    Route::get('/callback', [DiscordRoleController::class, 'callback'])
                        ->middleware('throttle:60,1')
                        ->name('callback');
                    Route::delete('/{connection}', [DiscordRoleController::class, 'destroy'])
                        ->middleware('throttle:sensitive')
                        ->whereNumber('connection')
                        ->name('destroy');
                });
            });
    });
Route::name('site.')->group(function () {
    Route::get('/extensions/{id}/assets/{asset}', ExtensionAssetController::class)
        ->where('asset', '[A-Za-z0-9/_.-]+')
        ->name('extensions.assets.show');
    Route::get('/media/{asset}', [MediaController::class, 'show'])->name('media.show');
    Route::get('/avatars/{user}/{version}', [AvatarController::class, 'show'])
        ->whereNumber('user')
        ->where('version', '[a-f0-9]{40}')
        ->name('avatars.show');
});
Route::get('/login', [AuthController::class, 'loginForm'])
    ->middleware('guest')
    ->name('login');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'throttle:auth', VerifyCaptcha::class.':login'])
    ->name('login.store');
Route::get('/register', [AuthController::class, 'registerForm'])
    ->middleware('guest')
    ->name('register');
Route::post('/register', [AuthController::class, 'register'])
    ->middleware(['guest', 'throttle:auth', VerifyCaptcha::class.':register'])
    ->name('register.store');
Route::prefix('in-game-sign-in')->name('in-game-sign-in.')->middleware('guest')->group(function () {
    Route::post('/', [InGameSignInController::class, 'start'])
        ->middleware('throttle:12,1,in-game-sign-in-start-')
        ->block()
        ->name('start');
    Route::get('/{id}', [InGameSignInController::class, 'status'])
        ->whereUuid('id')
        ->middleware('throttle:120,1,in-game-sign-in-status-')
        ->name('status');
    Route::post('/{id}/resend', [InGameSignInController::class, 'resend'])
        ->whereUuid('id')
        ->middleware('throttle:12,1,in-game-sign-in-resend-')
        ->block()
        ->name('resend');
    Route::post('/{id}/cancel', [InGameSignInController::class, 'cancel'])
        ->whereUuid('id')
        ->middleware('throttle:12,1,in-game-sign-in-cancel-')
        ->block()
        ->name('cancel');
    Route::post('/{id}/finish', [InGameSignInController::class, 'finish'])
        ->whereUuid('id')
        ->middleware('throttle:12,1,in-game-sign-in-finish-')
        ->block()
        ->name('finish');
});
Route::name('password.')
    ->middleware('guest')
    ->group(function () {
        Route::get('/forgot-password', [AuthController::class, 'forgotForm'])->name('request');
        Route::post('/forgot-password', [AuthController::class, 'forgot'])
            ->middleware('throttle:auth')
            ->name('email');
        Route::get('/reset-password/{token}', [AuthController::class, 'resetForm'])->name('reset');
        Route::post('/reset-password', [AuthController::class, 'reset'])
            ->middleware('throttle:auth')
            ->name('update');
    });
Route::name('auth.')->group(function () {
    Route::name('registration.')->prefix('register/complete')->middleware('guest')->group(function () {
        Route::get('/', [RegistrationCompletionController::class, 'index'])->name('complete');
        Route::post('/', [RegistrationCompletionController::class, 'store'])
            ->middleware('throttle:sensitive')
            ->block()
            ->name('store');
    });
    Route::name('two_factor.')
        ->prefix('two-factor-challenge')
        ->middleware('guest')
        ->group(function () {
            Route::get('/', [AuthController::class, 'challengeForm'])->name('challenge');
            Route::post('/', [AuthController::class, 'challenge'])
                ->middleware('throttle:sensitive')
                ->name('verify');
        });
    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('logout');
    Route::get('/verify-game', function (Request $request, OAuthRegistry $registry) {
        $pending = $request->session()->get('auth.identity_pending');
        if (!$pending) {
            throw new HttpException(403);
        }
        if ($pending['at'] <= time() - LoginManager::IDENTITY_PENDING_SECONDS) {
            $request->session()->forget('auth.identity_pending');

            return redirect()->route('login');
        }
        $games = app(GameRegistry::class);
        $metadata = $games->active()[$games->activeId()] ?? [];
        if (!empty($metadata['verification_url'])) {
            return redirect($metadata['verification_url']);
        }

        $adapter = $metadata['connection_adapter'] ?? null;
        $actions = array_filter(
            $metadata['verification_actions'] ?? [],
            fn (array $action) => Route::has($action['route']) &&
                (!isset($action['available']) || app()->call($action['available'])),
        );

        return AuthForms::render(
            $request,
            'default.user.account.games.verify',
            'default.user.auth.partials.verify-game',
            __('site.auth.security.identity_required'),
            [
                'providers' => $adapter === null ? $registry->enabled() : $registry->enabledForAdapter($adapter),
                'registry' => $registry,
                'actions' => $actions,
            ],
        );
    })
        ->middleware('guest')
        ->name('games.verify');
});
Route::name('verification.')
    ->middleware('auth')
    ->group(function () {
        Route::get('/verify-email', fn (Request $request) => AuthForms::render(
            $request,
            'default.user.auth.verify_email',
            'default.user.auth.partials.verify-email',
            __('site.auth.verification.heading'),
        ))->name('notice');
        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verify'])
            ->middleware('signed')
            ->name('verify');
        Route::post('/email/verification-notification', [AuthController::class, 'sendVerification'])
            ->middleware('throttle:sensitive')
            ->name('send');
    });
Route::name('site.')->group(function () {
    Route::name('news.')
        ->prefix('news')
        ->group(function () {
            Route::get('/', [ContentController::class, 'index'])
                ->middleware('capability:hasNews')
                ->name('index');
            Route::get('/{slug}', [ContentController::class, 'news'])->name('show');
            Route::name('comments.')
                ->prefix('{entry}/comments')
                ->middleware('auth')
                ->group(function () {
                    Route::post('/', [ContentCommentController::class, 'store'])
                        ->middleware(['verified', 'throttle:10,1'])
                        ->whereNumber('entry')
                        ->name('store');
                    Route::post('/{comment}', [ContentCommentController::class, 'update'])
                        ->middleware(['verified', 'throttle:10,1'])
                        ->whereNumber(['entry', 'comment'])
                        ->name('update');
                    Route::delete('/{comment}', [ContentCommentController::class, 'destroy'])
                        ->middleware('throttle:30,1')
                        ->whereNumber(['entry', 'comment'])
                        ->name('destroy');
                });
            Route::name('reactions.')
                ->prefix('{entry}/reaction')
                ->middleware(['auth', 'throttle:30,1'])
                ->group(function () {
                    Route::post('/', [ContentCommentController::class, 'reaction'])
                        ->whereNumber('entry')
                        ->name('store');
                    Route::delete('/', [ContentCommentController::class, 'reaction'])
                        ->whereNumber('entry')
                        ->name('destroy');
                });
        });
    Route::get('/p/{slug}', [ContentController::class, 'page'])
        ->where('slug', '[a-z0-9/-]+')
        ->name('pages.show');
    Route::name('content.')
        ->prefix('content')
        ->group(function () {
            Route::get('/{entry}/cover', [ContentCoverController::class, 'show'])
                ->whereNumber('entry')
                ->name('cover');
            Route::get('/{entry}/attachments/{attachment}', [ContentController::class, 'attachment'])
                ->whereNumber(['entry', 'attachment'])
                ->name('attachments.download');
            Route::get('/images/{image}', [ContentImageController::class, 'show'])
                ->whereUuid('image')
                ->name('images.show');
        });
});
Route::name('install.')
    ->prefix('install')
    ->group(function () {
        Route::get('/', [InstallController::class, 'index'])->name('index');
        Route::get('/finish', [InstallController::class, 'finish'])->name('finish');
        Route::post('/', [InstallController::class, 'store'])
            ->middleware(['throttle:3,1', 'operation:exclusive', 'operation:launch'])
            ->name('store');
    });
Route::name('auth.')->group(function () {
    Route::name('oauth.')
        ->prefix('oauth/{provider}')
        ->middleware('throttle:auth')
        ->group(function () {
            Route::get('/', [OAuthController::class, 'redirect'])->name('redirect');
            Route::get('/callback', [OAuthController::class, 'callback'])->name('callback');
        });
    Route::name('registration.')
        ->prefix('register/external')
        ->middleware('guest')
        ->group(function () {
            Route::name('external.')->group(function () {
                Route::get('/', [OAuthController::class, 'registration'])->name('create');
                Route::post('/', [OAuthController::class, 'register'])
                    ->middleware(['throttle:auth', VerifyCaptcha::class.':register'])
                    ->name('store');
            });
        });
});
Route::name('site.')->group(function () {
    Route::get('/dates/local', LocalDateController::class)->middleware('throttle:120,1')->name('dates.local');
    Route::post('/appearance', AppearanceController::class)->middleware('throttle:60,1')->name('appearance.update');
    Route::get('/', [PublicController::class, 'home'])->name('home.index');
    Route::get('/language/{locale}', [PublicController::class, 'locale'])->name('language.update');
    Route::get('/sitemap.xml', [PublicController::class, 'sitemap'])->name('sitemap.index');
    Route::get('/robots.txt', [PublicController::class, 'robots'])->name('robots.index');
    Route::get('/feeds/{format}.xml', [PublicFeedController::class, 'show'])
        ->middleware('throttle:120,1')
        ->where('format', 'rss|atom')
        ->name('feeds.show');
    Route::get('/site.webmanifest', SiteManifestController::class)->name('manifest.show');
});
Route::name('site.')->group(function () {
    Route::fallback([RedirectController::class, 'resolve'])->name('redirects.resolve');
});

require __DIR__.'/passkeys.php';
