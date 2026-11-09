<?php

namespace App\Providers;

use App\Kraft\Events\PluginEvents;
use Illuminate\Support\ServiceProvider;

class PluginEventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginEvents::class);
    }
}
