<?php

use App\Http\Controllers\Auth\PasskeyController;
use Illuminate\Support\Facades\Route;

Route::prefix('login/passkey')->name('auth.passkeys.')->middleware(['guest', 'throttle:30,1,passkeys'])->group(function () {
    Route::post('/options', [PasskeyController::class, 'loginOptions'])->block()->name('options');
    Route::post('/', [PasskeyController::class, 'login'])->block()->name('login');
});

Route::prefix('account/passkeys')->name('account.passkeys.')->middleware(['auth', 'throttle:sensitive'])->group(function () {
    Route::post('/options', [PasskeyController::class, 'enrollmentOptions'])->block()->name('options');
    Route::post('/', [PasskeyController::class, 'enroll'])->block()->name('store');
    Route::delete('/{key}', [PasskeyController::class, 'destroy'])->whereNumber('key')->block()->name('destroy');
});
