<?php

namespace App\Providers;

use App\Kraft\Widgets\WidgetLayout;
use App\Kraft\Widgets\WidgetRegistry;
use Illuminate\Support\ServiceProvider;

class WidgetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WidgetRegistry::class);
        $this->app->scoped(WidgetLayout::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/widgets.php'));
    }
}
