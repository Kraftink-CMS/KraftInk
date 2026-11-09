<?php

use App\Http\Controllers\Admin\Users\ServiceAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/service-accounts')
    ->name('admin.service_accounts.')
    ->middleware(['web', 'auth', 'permission:admin.access', 'permission:service-accounts.manage'])
    ->group(function (): void {
        Route::get('/', [ServiceAccountController::class, 'index'])->name('index');
        Route::get('/create', [ServiceAccountController::class, 'create'])->name('create');
        Route::post('/', [ServiceAccountController::class, 'store'])
            ->middleware('throttle:sensitive')
            ->name('store');
        Route::whereNumber('account')->group(function (): void {
            Route::get('/{account}', [ServiceAccountController::class, 'edit'])->name('edit');
            Route::post('/{account}', [ServiceAccountController::class, 'update'])->name('update');
            Route::post('/{account}/scopes', [ServiceAccountController::class, 'scopes'])
                ->middleware('throttle:sensitive')
                ->name('scopes');
            Route::post('/{account}/enabled', [ServiceAccountController::class, 'enabled'])
                ->middleware('throttle:sensitive')
                ->name('enabled');
            Route::post('/{account}/tokens', [ServiceAccountController::class, 'issue'])
                ->middleware('throttle:sensitive')
                ->name('tokens.store');
            Route::delete('/{account}/tokens/{token?}', [ServiceAccountController::class, 'revoke'])
                ->whereUuid('token')
                ->name('tokens.revoke');
            Route::post('/{account}/tokens/{token}/rotate', [ServiceAccountController::class, 'rotate'])
                ->middleware('throttle:sensitive')
                ->whereUuid('token')
                ->name('tokens.rotate');
        });
    });
