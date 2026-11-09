<?php

namespace App\Providers;

use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Wallet\WalletPrivacy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrencyRegistry::class);
    }

    public function boot(): void
    {
        Event::listen(ExportingPersonalData::class, [WalletPrivacy::class, 'export']);
        Event::listen(AccountDeleting::class, [WalletPrivacy::class, 'anonymize']);
    }
}
