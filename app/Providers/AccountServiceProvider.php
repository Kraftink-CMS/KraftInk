<?php

namespace App\Providers;

use App\Kraft\Auth\AdministrationPrivacy;
use App\Kraft\Auth\AvatarResolver;
use App\Kraft\Auth\Avatars;
use App\Kraft\Auth\DefaultRole;
use App\Kraft\Extensions\ExtensionPrivacy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Events\ExtensionStateChanging;
use App\Kraft\OAuth\OAuthChanges;
use App\Kraft\Auth\AuthMails;
use App\Kraft\Auth\PermissionCatalog;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;

class AccountServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PermissionCatalog::class);
        $this->app->scoped(AvatarResolver::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/service-accounts.php'));
        ResetPassword::toMailUsing(fn ($user, $token) => app(AuthMails::class)->reset($user, $token));
        VerifyEmail::toMailUsing(fn ($user, $url) => app(AuthMails::class)->verify($user, $url));
        Event::listen(Registered::class, [DefaultRole::class, 'registered']);
        Event::listen(AccountDeleting::class, [Avatars::class, 'anonymize']);
        Event::listen(ExportingPersonalData::class, [ExtensionPrivacy::class, 'export']);
        Event::listen(AccountDeleting::class, [ExtensionPrivacy::class, 'anonymize']);
        Event::listen(ExportingPersonalData::class, [AdministrationPrivacy::class, 'export']);
        Event::listen(AccountDeleting::class, [AdministrationPrivacy::class, 'anonymize']);
        Event::listen(ExtensionStateChanging::class, [OAuthChanges::class, 'extensionStateChanging']);
    }
}
