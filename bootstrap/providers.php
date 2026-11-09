<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\PluginEventServiceProvider::class,
    App\Providers\WidgetServiceProvider::class,
    App\Providers\NotificationServiceProvider::class,
    App\Providers\ThemeServiceProvider::class,
    App\Providers\ViewServiceProvider::class,
    App\Providers\SystemServiceProvider::class,
    App\Providers\AccountServiceProvider::class,
    App\Providers\ContentServiceProvider::class,
    App\Providers\WalletServiceProvider::class,
    App\Providers\DiscordRoleServiceProvider::class,
    App\Providers\LinkAccountServiceProvider::class,
];
