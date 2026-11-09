<?php

use App\Http\Controllers\Admin\Appearance\WidgetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'permission:admin.access', 'permission:content.manage'])
    ->prefix('admin/widgets')
    ->name('admin.widgets.')
    ->group(function (): void {
        Route::get('/', [WidgetController::class, 'index'])->name('index');
        Route::get('/create', [WidgetController::class, 'edit'])->name('create');
        Route::get('/{widget}/edit', [WidgetController::class, 'edit'])
            ->whereUuid('widget')
            ->name('edit');
        Route::post('/{widget?}', [WidgetController::class, 'store'])
            ->whereUuid('widget')
            ->name('store');
        Route::post('/{widget}/move', [WidgetController::class, 'move'])
            ->whereUuid('widget')
            ->name('move');
        Route::delete('/{widget}', [WidgetController::class, 'destroy'])
            ->whereUuid('widget')
            ->name('destroy');
    });
