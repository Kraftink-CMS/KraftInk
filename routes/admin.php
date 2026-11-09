<?php

use App\Http\Controllers\Admin\Settings\AccountSettingsController;
use App\Http\Controllers\Admin\Settings\MarketSettingsController;
use App\Http\Controllers\Admin\Content\AdminContentController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\System\AdminImportController;
use App\Http\Controllers\Admin\Content\AdminNewsController;
use App\Http\Controllers\Admin\Content\AdminPageController;
use App\Http\Controllers\Admin\Users\AdminUserController;
use App\Http\Controllers\Admin\Content\AnnouncementController;
use App\Http\Controllers\Admin\System\AuditLogController;
use App\Http\Controllers\Admin\System\AuditWebhookController;
use App\Http\Controllers\Auth\AuthApiController;
use App\Http\Controllers\Admin\Settings\AvatarSettingsController;
use App\Http\Controllers\Admin\System\CacheController;
use App\Http\Controllers\Content\ContentCoverController;
use App\Http\Controllers\Content\ContentImageController;
use App\Http\Controllers\Admin\Content\ContentSeoPreviewController;
use App\Http\Controllers\Admin\System\DataRetentionController;
use App\Http\Controllers\Admin\Settings\DiscordRoleSettingsController;
use App\Http\Controllers\Extensions\ExtensionOperationController;
use App\Http\Controllers\Games\GameController;
use App\Http\Controllers\Admin\Appearance\HomeConfigController;
use App\Http\Controllers\Link\LinkAccountAccessController;
use App\Http\Controllers\Link\LinkController;
use App\Http\Controllers\Link\LinkDeliveryController;
use App\Http\Controllers\Admin\System\MaintenanceController;
use App\Http\Controllers\Content\MediaController;
use App\Http\Controllers\Admin\Appearance\NavigationController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Admin\Settings\OAuthSettingsController;
use App\Http\Controllers\Site\RedirectController;
use App\Http\Controllers\Admin\Users\RoleController;
use App\Http\Controllers\Admin\Settings\SecuritySettingsController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Controllers\Games\ServerController;
use App\Http\Controllers\Games\ServerTransportController;
use App\Http\Middleware\EnsureKraftLinkServer;
use App\Http\Controllers\Admin\Settings\SiteSettingsController;
use App\Http\Controllers\Admin\Appearance\SliderController;
use App\Http\Controllers\Admin\Appearance\SocialLinkController;
use App\Http\Controllers\Admin\System\SystemController;
use App\Http\Controllers\Admin\Appearance\ThemeConfigurationController;
use App\Http\Controllers\Admin\System\TrashController;
use App\Http\Controllers\Admin\System\UpdateController;
use App\Http\Controllers\Account\WalletController;
use Illuminate\Support\Facades\Route;

Route::name('admin.')
    ->prefix('admin')
    ->middleware(['auth', 'permission:admin.access'])
    ->group(function () {
        Route::get('/trash', [TrashController::class, 'index'])->name('trash.index');
        Route::delete('/trash', [TrashController::class, 'empty'])
            ->middleware('throttle:sensitive')
            ->name('trash.empty');
        Route::delete('/trash/{source}/{item}', [TrashController::class, 'purge'])
            ->middleware('throttle:sensitive')
            ->where(['source' => '[a-z][a-z0-9_.-]{0,79}', 'item' => '[^/]{1,255}'])
            ->name('trash.purge');
        Route::post('/trash/{source}/{item}/restore', [TrashController::class, 'restore'])
            ->middleware('throttle:sensitive')
            ->where(['source' => '[a-z][a-z0-9_.-]{0,79}', 'item' => '[^/]{1,255}'])
            ->name('trash.restore');
        foreach (
            ['news' => AdminNewsController::class, 'pages' => AdminPageController::class] as $section => $controller
        ) {
            Route::name($section.'.')
                ->prefix($section)
                ->middleware('permission:content.manage')
                ->group(function () use ($controller) {
                    Route::get('/', [$controller, 'index'])->name('index');
                    Route::get('/create', [$controller, 'edit'])->name('create');
                    Route::get('/{entry}/edit', [$controller, 'edit'])
                        ->whereNumber('entry')
                        ->name('edit');
                    Route::post('/{entry?}', [$controller, 'store'])
                        ->whereNumber('entry')
                        ->name('store');
                    Route::delete('/{entry}', [$controller, 'destroy'])
                        ->whereNumber('entry')
                        ->name('destroy');
                    Route::get('/{entry}/history', [$controller, 'history'])
                        ->whereNumber('entry')
                        ->name('history');
                    Route::post('/{entry}/revisions/{revision}/restore', [$controller, 'restore'])
                        ->middleware(['permission:content.restore', 'throttle:sensitive'])
                        ->whereNumber(['entry', 'revision'])
                        ->name('restore');
                    Route::name('attachments.')
                        ->prefix('{entry}/attachments')
                        ->group(function () use ($controller) {
                            Route::post('/', [$controller, 'attachment'])
                                ->middleware('throttle:20,1')
                                ->whereNumber('entry')
                                ->name('store');
                            Route::delete('/{attachment}', [$controller, 'deleteAttachment'])
                                ->whereNumber(['entry', 'attachment'])
                                ->name('destroy');
                            Route::get('/{attachment}/download', [$controller, 'download'])
                                ->whereNumber(['entry', 'attachment'])
                                ->name('download');
                        });
                });
        }
        Route::name('content.')
            ->prefix('content')
            ->group(function () {
                Route::name('announcements.')
                    ->prefix('announcements')
                    ->middleware(['permission:content.manage', 'permission:settings.manage'])
                    ->group(function () {
                        Route::get('/', [AnnouncementController::class, 'index'])->name('index');
                        Route::post('/', [AnnouncementController::class, 'store'])
                            ->middleware('throttle:sensitive')
                            ->name('store');
                        Route::post('/{delivery}/retry', [AnnouncementController::class, 'retry'])
                            ->middleware('throttle:sensitive')
                            ->whereUuid('delivery')
                            ->name('retry');
                    });
                Route::post('/images', [ContentImageController::class, 'store'])
                    ->middleware(['permission:content.manage', 'throttle:30,1'])
                    ->name('images.store');
                Route::get('/cover-images', [ContentCoverController::class, 'index'])
                    ->middleware('permission:content.manage')
                    ->name('covers.index');
                Route::post('/cover-images', [ContentCoverController::class, 'store'])
                    ->middleware(['permission:content.manage', 'throttle:30,1'])
                    ->name('covers.store');
                Route::post('/seo-preview', ContentSeoPreviewController::class)
                    ->middleware('permission:content.manage')
                    ->name('seo.preview');
                Route::name('comments.')
                    ->prefix('comments')
                    ->middleware('permission:content.comments.manage')
                    ->group(function () {
                        Route::get('/', [AdminContentController::class, 'comments'])->name('index');
                        Route::post('/{comment}', [AdminContentController::class, 'moderate'])
                            ->whereNumber('comment')
                            ->name('moderate');
                        Route::get('/{comment}/history', [AdminContentController::class, 'commentHistory'])
                            ->whereNumber('comment')
                            ->name('history');
                        Route::post('/{comment}/revisions/{revision}/restore', [
                            AdminContentController::class,
                            'restoreComment',
                        ])
                            ->middleware(['permission:content.restore', 'throttle:sensitive'])
                            ->whereNumber(['comment', 'revision'])
                            ->name('restore');
                    });
            });
        Route::name('dashboard.')->group(function () {
            Route::get('/', [AdminController::class, 'dashboard'])->name('index');
        });
        Route::name('themes.configuration.')
            ->prefix('themes/{theme}/configuration')
            ->where(['theme' => '[a-z][a-z0-9-]{1,49}'])
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [ThemeConfigurationController::class, 'index'])->name('index');
                Route::post('/', [ThemeConfigurationController::class, 'store'])->name('store');
                Route::post('/preview', [ThemeConfigurationController::class, 'preview'])->name('preview');
            });
        Route::name('extensions.')
            ->prefix('extensions')
            ->middleware('permission:extensions.manage')
            ->group(function () {
                Route::get('/', fn () => redirect()->route('admin.extensions.plugins.index'))->name('index');
                Route::get('/plugins', [ExtensionOperationController::class, 'plugins'])->name('plugins.index');
                Route::get('/market', [MarketSettingsController::class, 'index'])->name('market.index');
                Route::post('/market', [MarketSettingsController::class, 'update'])
                    ->middleware('throttle:sensitive')
                    ->name('market.update');
                Route::name('themes.')
                    ->prefix('themes')
                    ->group(function () {
                        Route::get('/', [ExtensionOperationController::class, 'themes'])->name('index');
                        Route::post('/select', [ExtensionOperationController::class, 'selectTheme'])->name('select');
                    });
                Route::post('/install', [ExtensionOperationController::class, 'prepare'])->name('install');
                Route::name('operations.')
                    ->prefix('operations/{id}')
                    ->group(function () {
                        Route::get('/', [ExtensionOperationController::class, 'show'])->name('show');
                        Route::post('/run', [ExtensionOperationController::class, 'run'])
                            ->middleware(['throttle:sensitive', 'operation:launch'])
                            ->name('run');
                    });
                Route::post('/{id}/toggle', [ExtensionOperationController::class, 'toggle'])->name('toggle');
                Route::post('/{id}/retire', [ExtensionOperationController::class, 'retire'])
                    ->middleware(['throttle:sensitive', 'operation:exclusive'])
                    ->where('id', '[a-z][a-z0-9-]{1,49}')
                    ->name('retire');
                Route::post('/{id}/uninstall', [ExtensionOperationController::class, 'uninstall'])
                    ->middleware(['throttle:sensitive', 'operation:exclusive'])
                    ->where('id', '[a-z][a-z0-9-]{1,49}')
                    ->name('uninstall');
                Route::post('/{id}/restore', [ExtensionOperationController::class, 'restore'])
                    ->middleware(['throttle:sensitive', 'operation:exclusive'])
                    ->where('id', '[a-z][a-z0-9-]{1,49}')
                    ->name('restore');
            });
        Route::name('games.')
            ->prefix('games')
            ->group(function () {
                Route::post('/', [GameController::class, 'save'])
                    ->middleware('permission:settings.manage')
                    ->name('update');
                Route::get('/', [GameController::class, 'admin'])->name('index');
            });
        Route::name('home.')
            ->prefix('home')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [HomeConfigController::class, 'index'])->name('index');
                Route::post('/', [HomeConfigController::class, 'store'])
                    ->middleware('throttle:20,1')
                    ->name('store');
            });
        Route::name('imports.')
            ->prefix('imports')
            ->middleware('permission:imports.manage')
            ->group(function () {
                Route::get('/', [AdminImportController::class, 'index'])->name('index');
                Route::post('/analyze', [AdminImportController::class, 'analyze'])
                    ->middleware(['throttle:3,1', 'operation:exclusive', 'operation:launch'])
                    ->name('analyze');
                Route::post('/execute', [AdminImportController::class, 'execute'])
                    ->middleware(['throttle:3,1', 'operation:exclusive'])
                    ->name('execute');
                Route::get('/progress', [AdminImportController::class, 'progress'])->name('progress');
                Route::get('/report/{id}', [AdminImportController::class, 'report'])
                    ->whereUuid('id')
                    ->name('report');
                Route::get('/report/{id}/download', [AdminImportController::class, 'downloadReport'])
                    ->whereUuid('id')
                    ->name('report.download');
                Route::post('/finish', [AdminImportController::class, 'finish'])->name('finish');
            });
        Route::name('media.selection.')
            ->prefix('media/selection')
            ->group(function () {
                Route::get('/', [MediaController::class, 'selection'])->name('index');
                Route::post('/', [MediaController::class, 'storeSelection'])
                    ->middleware('throttle:20,1')
                    ->name('store');
            });
        Route::name('media.')
            ->prefix('media')
            ->middleware('permission:media.manage')
            ->group(function () {
                Route::get('/', [MediaController::class, 'index'])->name('index');
                Route::post('/', [MediaController::class, 'store'])
                    ->middleware('throttle:20,1')
                    ->name('store');
                Route::post('/{asset}', [MediaController::class, 'update'])->name('update');
                Route::delete('/{asset}', [MediaController::class, 'destroy'])->name('destroy');
            });
        Route::name('navigation.')
            ->prefix('navigation')
            ->middleware('permission:navigation.manage')
            ->group(function () {
                Route::get('/', [NavigationController::class, 'index'])->name('index');
                Route::post('/{link?}', [NavigationController::class, 'store'])->name('store');
                Route::post('/{link}/move', [NavigationController::class, 'move'])->name('move');
                Route::delete('/{link}', [NavigationController::class, 'destroy'])->name('destroy');
            });
        Route::name('notifications.')
            ->prefix('notifications')
            ->middleware('permission:notifications.manage')
            ->group(function () {
                Route::get('/', [NotificationController::class, 'admin'])->name('index');
                Route::get('/create', [NotificationController::class, 'create'])->name('create');
                Route::post('/', [NotificationController::class, 'store'])
                    ->middleware('throttle:5,1')
                    ->name('store');
                Route::delete('/{notice}', [NotificationController::class, 'destroy'])->name('destroy');
            });
        Route::name('retention.')
            ->prefix('operations/retention')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [DataRetentionController::class, 'index'])->name('index');
                Route::post('/', [DataRetentionController::class, 'store'])->name('store');
            });
        Route::name('operations.audit.')
            ->prefix('operations/audit')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [AuditLogController::class, 'index'])->name('index');
                Route::get('/export', [AuditLogController::class, 'export'])
                    ->middleware('throttle:3,1')
                    ->name('export');
                Route::post('/retention', [AuditLogController::class, 'retention'])->name('retention');
                Route::post('/purge', [AuditLogController::class, 'purge'])
                    ->middleware('throttle:sensitive')
                    ->name('purge');
                Route::get('/{entry}', [AuditLogController::class, 'show'])
                    ->whereNumber('entry')
                    ->name('show');
            });
        Route::name('operations.cache.')
            ->prefix('operations/cache')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [CacheController::class, 'index'])->name('index');
                Route::post('/', [CacheController::class, 'run'])
                    ->middleware('throttle:sensitive')
                    ->name('run');
            });
        Route::name('operations.maintenance.')
            ->prefix('operations/maintenance')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [MaintenanceController::class, 'index'])->name('index');
                Route::post('/', [MaintenanceController::class, 'store'])->name('store');
            });
        Route::name('operations.webhooks.')
            ->prefix('operations/webhooks')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [AuditWebhookController::class, 'index'])->name('index');
                Route::post('/', [AuditWebhookController::class, 'store'])
                    ->middleware('throttle:sensitive')
                    ->name('store');
                Route::post('/test', [AuditWebhookController::class, 'test'])
                    ->middleware('throttle:sensitive')
                    ->name('test');
                Route::post('/{delivery}/retry', [AuditWebhookController::class, 'retry'])
                    ->middleware('throttle:sensitive')
                    ->whereUuid('delivery')
                    ->name('retry');
            });
        Route::name('roles.')
            ->prefix('roles')
            ->middleware('permission:roles.manage')
            ->group(function () {
                Route::get('/', [RoleController::class, 'index'])->name('index');
                Route::get('/create', [RoleController::class, 'edit'])->name('create');
                Route::get('/{role}/edit', [RoleController::class, 'edit'])
                    ->whereNumber('role')
                    ->name('edit');
                Route::name('defaults.')
                    ->prefix('defaults')
                    ->group(function () {
                        Route::post('/', [RoleController::class, 'permissions'])->name('update');
                        Route::get('/', [RoleController::class, 'permissionDefaults'])->name('index');
                    });
                Route::post('/{role?}', [RoleController::class, 'store'])
                    ->whereNumber('role')
                    ->name('store');
                Route::post('/{role}/duplicate', [RoleController::class, 'duplicate'])
                    ->whereNumber('role')
                    ->name('duplicate');
                Route::delete('/{role}', [RoleController::class, 'destroy'])
                    ->whereNumber('role')
                    ->name('destroy');
            });
        Route::name('seo.')
            ->prefix('seo')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [SeoController::class, 'index'])->name('index');
                Route::post('/', [SeoController::class, 'update'])->name('update');
                Route::get('/pages/create', [SeoController::class, 'create'])->name('pages.create');
                Route::get('/pages/{rule}/edit', [SeoController::class, 'edit'])
                    ->whereUuid('rule')
                    ->name('pages.edit');
                Route::post('/pages/{rule?}', [SeoController::class, 'store'])
                    ->whereUuid('rule')
                    ->name('pages.store');
                Route::delete('/pages/{rule}', [SeoController::class, 'destroy'])
                    ->whereUuid('rule')
                    ->name('pages.destroy');
            });
        Route::name('seo.redirects.')
            ->prefix('seo/redirects')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [RedirectController::class, 'index'])->name('index');
                Route::post('/{redirect?}', [RedirectController::class, 'store'])->name('store');
                Route::delete('/{redirect}', [RedirectController::class, 'destroy'])->name('destroy');
            });
        Route::get('/servers/deliveries', [LinkDeliveryController::class, 'index'])->name('servers.deliveries.index');
        Route::post('/servers/deliveries/{id}/cancel', [LinkDeliveryController::class, 'cancel'])
            ->whereUuid('id')
            ->middleware(['permission:servers.manage', 'throttle:sensitive'])
            ->name('servers.deliveries.cancel');
        Route::name('servers.')
            ->prefix('servers')
            ->middleware('permission:servers.manage')
            ->group(function () {
                Route::get('/', [ServerController::class, 'index'])->name('index');
                Route::match(['POST', 'DELETE'], '/{server}/primary', [ServerController::class, 'primary'])
                    ->whereNumber('server')
                    ->name('primary');
                Route::name('transport.')
                    ->prefix('{server}/transport')
                    ->group(function () {
                        Route::post('/configuration', [ServerTransportController::class, 'configure'])
                            ->middleware('throttle:sensitive')
                            ->whereNumber('server')
                            ->name('configure');
                        Route::post('/check', [ServerTransportController::class, 'check'])
                            ->middleware('throttle:sensitive')
                            ->whereNumber('server')
                            ->name('check');
                        Route::post('/commands', [ServerTransportController::class, 'enqueue'])
                            ->middleware('throttle:sensitive')
                            ->whereNumber('server')
                            ->name('enqueue');
                    });
                Route::post('/{server?}', [ServerController::class, 'store'])->name('store');
                Route::delete('/{server}', [ServerController::class, 'destroy'])->name('destroy');
                Route::name('link.')
                    ->prefix('{server}/link')
                    ->group(function () {
                        Route::get('/', [ServerController::class, 'link'])
                            ->whereNumber('server')
                            ->name('show');
                        Route::middleware(EnsureKraftLinkServer::class)->group(function () {
                            Route::post('/token', [LinkController::class, 'token'])
                                ->middleware('throttle:sensitive')
                                ->name('token');
                            Route::post('/commands', [LinkController::class, 'enqueue'])
                                ->middleware('throttle:sensitive')
                                ->name('enqueue');
                            Route::name('accounts.')
                                ->prefix('accounts')
                                ->group(function () {
                                    Route::get('/', [LinkAccountAccessController::class, 'index'])->name('index');
                                    Route::post('/', [LinkAccountAccessController::class, 'issue'])
                                        ->middleware('throttle:sensitive')
                                        ->name('issue');
                                    Route::delete('/{token}', [LinkAccountAccessController::class, 'revoke'])
                                        ->middleware('throttle:sensitive')
                                        ->whereUuid('token')
                                        ->name('revoke');
                                });
                        });
                    });
            });
        Route::name('settings.accounts.')
            ->prefix('settings/accounts')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [AccountSettingsController::class, 'index'])->name('index');
                Route::post('/', [AccountSettingsController::class, 'store'])
                    ->middleware('throttle:sensitive')
                    ->name('update');
            });
        Route::name('settings.api.')
            ->prefix('settings/api')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [AuthApiController::class, 'settings'])->name('index');
                Route::post('/', [AuthApiController::class, 'configure'])
                    ->middleware('throttle:sensitive')
                    ->name('update');
            });
        Route::name('settings.avatars.')
            ->prefix('settings/avatars')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [AvatarSettingsController::class, 'index'])->name('index');
                Route::post('/', [AvatarSettingsController::class, 'store'])
                    ->middleware('throttle:sensitive')
                    ->name('update');
            });
        Route::name('settings.discord.')
            ->prefix('settings/discord')
            ->middleware(['permission:settings.manage', 'permission:roles.manage'])
            ->group(function () {
                Route::get('/', [DiscordRoleSettingsController::class, 'index'])->name('index');
                Route::post('/', [DiscordRoleSettingsController::class, 'store'])
                    ->middleware('throttle:sensitive')
                    ->name('update');
                Route::post('/publish', [DiscordRoleSettingsController::class, 'publish'])
                    ->middleware('throttle:sensitive')
                    ->name('publish');
                Route::post('/retry', [DiscordRoleSettingsController::class, 'retry'])
                    ->middleware('throttle:sensitive')
                    ->name('retry');
            });
        Route::name('settings.oauth.')
            ->prefix('settings/oauth')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [OAuthSettingsController::class, 'index'])->name('index');
                Route::post('/', [OAuthSettingsController::class, 'store'])
                    ->middleware('throttle:sensitive')
                    ->name('update');
                Route::post('/providers', [OAuthSettingsController::class, 'add'])
                    ->middleware('throttle:sensitive')
                    ->name('providers.store');
                Route::post('/preview', [OAuthSettingsController::class, 'preview'])->name('preview');
                Route::get('/preview/{id}', [OAuthSettingsController::class, 'reviewPage'])
                    ->whereUuid('id')
                    ->name('review');
                Route::post('/changes', [OAuthSettingsController::class, 'apply'])
                    ->middleware('throttle:sensitive')
                    ->name('apply');
                Route::post('/recovery', [OAuthSettingsController::class, 'retry'])
                    ->middleware('throttle:sensitive')
                    ->name('recovery.retry');
            });
        Route::name('settings.security.')
            ->prefix('settings/security')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [SecuritySettingsController::class, 'index'])->name('index');
                Route::post('/', [SecuritySettingsController::class, 'store'])
                    ->middleware('throttle:sensitive')
                    ->name('update');
            });
        Route::name('settings.site.')
            ->prefix('settings/site')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [SiteSettingsController::class, 'index'])->name('index');
                Route::post('/', [SiteSettingsController::class, 'update'])->name('update');
            });
        Route::name('settings.system.')
            ->prefix('settings/system')
            ->middleware('permission:settings.manage')
            ->group(function () {
                Route::get('/', [SystemController::class, 'index'])->name('index');
                Route::name('mail.')
                    ->prefix('mail')
                    ->group(function () {
                        Route::post('/', [SystemController::class, 'saveMail'])->name('update');
                        Route::post('/test', [SystemController::class, 'testMail'])
                            ->middleware('throttle:3,10')
                            ->name('test');
                    });
            });
        Route::name('slides.')
            ->prefix('slides')
            ->middleware('permission:content.manage')
            ->group(function () {
                Route::get('/', [SliderController::class, 'index'])->name('index');
                Route::get('/create', [SliderController::class, 'edit'])->name('create');
                Route::get('/{slide}/edit', [SliderController::class, 'edit'])->name('edit');
                Route::post('/{slide?}', [SliderController::class, 'store'])
                    ->middleware('throttle:20,1')
                    ->name('store');
                Route::post('/{slide}/visibility', [SliderController::class, 'toggle'])->name('visibility');
                Route::post('/{slide}/move', [SliderController::class, 'move'])->name('move');
                Route::delete('/{slide}', [SliderController::class, 'destroy'])->name('destroy');
            });
        Route::name('social.')
            ->prefix('social')
            ->middleware('permission:navigation.manage')
            ->group(function () {
                Route::get('/', [SocialLinkController::class, 'index'])->name('index');
                Route::post('/{link?}', [SocialLinkController::class, 'store'])
                    ->whereUuid('link')
                    ->name('store');
                Route::post('/{link}/move', [SocialLinkController::class, 'move'])
                    ->whereUuid('link')
                    ->name('move');
                Route::delete('/{link}', [SocialLinkController::class, 'destroy'])
                    ->whereUuid('link')
                    ->name('destroy');
            });
        Route::name('updates.')
            ->prefix('updates')
            ->group(function () {
                Route::get('/', [UpdateController::class, 'index'])
                    ->middleware('permission:updates.manage')
                    ->name('index');
                Route::post('/availability', [UpdateController::class, 'availability'])
                    ->middleware('throttle:60,1')
                    ->name('availability');
                Route::post('/channel', [UpdateController::class, 'channel'])
                    ->middleware(['permission:updates.manage', 'throttle:sensitive'])
                    ->name('channel');
                Route::post('/check', [UpdateController::class, 'check'])
                    ->middleware(['permission:updates.manage', 'throttle:sensitive'])
                    ->name('check');
                Route::post('/prepare', [UpdateController::class, 'prepare'])
                    ->middleware(['permission:updates.manage', 'throttle:sensitive'])
                    ->name('prepare');
                Route::post('/stage', [UpdateController::class, 'stage'])
                    ->middleware(['permission:updates.manage', 'throttle:sensitive'])
                    ->name('stage');
                Route::post('/{id}/run', [UpdateController::class, 'run'])
                    ->middleware(['permission:updates.manage', 'throttle:sensitive', 'operation:launch'])
                    ->name('run');
            });
        Route::name('users.')
            ->prefix('users')
            ->middleware('permission:users.manage')
            ->group(function () {
                Route::get('/', [AdminUserController::class, 'index'])->name('index');
                Route::get('/create', [AdminUserController::class, 'create'])->name('create');
                Route::get('/{user}/edit', [AdminUserController::class, 'edit'])
                    ->whereNumber('user')
                    ->name('edit');
                Route::post('/{user?}', [AdminUserController::class, 'store'])
                    ->whereNumber('user')
                    ->name('store');
                Route::post('/{user}/actions', [AdminUserController::class, 'action'])
                    ->middleware('throttle:sensitive')
                    ->whereNumber('user')
                    ->name('actions.store');
            });
        Route::name('wallet.')
            ->prefix('wallet')
            ->middleware('permission:wallet.manage')
            ->group(function () {
                Route::get('/', [WalletController::class, 'admin'])->name('index');
                Route::post('/adjust', [WalletController::class, 'adjust'])
                    ->middleware('throttle:sensitive')
                    ->name('adjust');
                Route::post('/settings', [WalletController::class, 'configure'])->name('settings.update');
                Route::get('/export', [WalletController::class, 'export'])
                    ->middleware('throttle:3,1')
                    ->name('export');
            });
    });
