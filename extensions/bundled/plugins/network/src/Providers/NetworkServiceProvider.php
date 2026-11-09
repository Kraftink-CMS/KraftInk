<?php

namespace KraftInk\Plugins\Network\Providers;

use Illuminate\Support\ServiceProvider;
use KraftInk\Plugins\Network\Http\Middleware\TrustProxies;

class NetworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/network.php', 'network');
        $this->app->bind(\App\Http\Middleware\TrustProxies::class, TrustProxies::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'network');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'network');
        $this->loadRoutesFrom(__DIR__.'/../../routes/admin.php');
    }
}
