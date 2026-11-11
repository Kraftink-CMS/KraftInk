<?php

use App\Http\Middleware\ExtensionConfiguration;
use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Network\Http\Controllers\Admin\NetworkController;

Route::prefix('admin/plugins/network')
    ->name('plugins.network.admin.')
    ->middleware(['web', 'auth', 'permission:admin.access', 'permission:settings.manage', ExtensionConfiguration::class.':network'])
    ->group(function () {
        Route::get('/', [NetworkController::class, 'index'])->name('index');
        Route::post('/', [NetworkController::class, 'save'])->middleware('throttle:sensitive')->name('update');
    });
