<?php

namespace App\Providers;

use App\Events\ConfigurationChanged;
use App\Http\Middleware\ExtensionConfiguration;
use App\Kraft\Auth\ApiScopes;
use App\Kraft\Auth\HashingPolicy;
use App\Kraft\Auth\PermissionAccess;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\InstallationReceipts;
use App\Kraft\Extensions\MarketSecurity;
use App\Kraft\Extensions\SlotRegistry;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Link\LinkPollRegistry;
use App\Kraft\Link\LinkRequestRegistry;
use App\Kraft\Features;
use App\Kraft\Setup\Installation;
use App\Kraft\LocalePolicy;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Media\MediaSelection;
use App\Kraft\Migration\MappingRegistry;
use App\Kraft\Settings;
use App\Kraft\Site\Seo;
use App\Kraft\Site\SeoPages;
use App\Kraft\Site\SiteIdentity;
use App\Kraft\System\OperationLock;
use App\Kraft\Trash\TrashRegistry;
use App\Models\Accounts\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('hash', fn ($manager, $app) => new HashingPolicy($app));
        $this->app->scoped(Settings::class, fn () => new Settings());
        $this->app->scoped(SiteIdentity::class);
        $this->app->scoped(Seo::class);
        $this->app->scoped(SeoPages::class);
        $this->app->scoped(GameRegistry::class);
        $this->app->singleton(LinkPollRegistry::class);
        $this->app->singleton(LinkRequestRegistry::class);
        $this->app->singleton(Features::class);
        $this->app->singleton(TrashRegistry::class);
        $this->app->singleton(ApiScopes::class);
        $this->app->scoped(LocalePolicy::class);
        $this->app->scoped(Installation::class);
        $this->app->scoped(OperationLock::class);
        $this->app->scoped(SlotRegistry::class);
        $this->app->scoped(MediaSelection::class);
        $this->app->scoped(FileDeletionQueue::class);
        $this->app->singleton(ExtensionManager::class);
        $this->app->scoped(InstallationReceipts::class);
        $this->app->scoped(MarketSecurity::class);
        $this->app->singleton(MappingRegistry::class);
    }

    public function boot(RateLimiter $limiter): void
    {
        Blade::anonymousComponentNamespace('admin.components', 'admin');
        app(SiteIdentity::class)->apply();
        Event::listen(ConfigurationChanged::class, function ($event): void {
            if ($event->key === 'site.url') {
                app(SiteIdentity::class)->apply();
            }
        });
        Paginator::defaultView('partials.pagination');
        Paginator::defaultSimpleView('partials.pagination');
        $limiter->for('auth', function (Request $request): array {
            $identifier = $request->input($request->routeIs('login.store') ? 'login' : 'email');

            return [
                Limit::perMinute(5)->by(
                    (is_string($identifier) ? mb_strtolower($identifier) : '').'|'.$request->ip(),
                ),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });
        $limiter->for(
            'sensitive',
            fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?? $request->ip()),
        );
        $linkLimits = function (Request $request): array {
            $token = $request->bearerToken();
            $connector = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1
                ? hash('sha256', $token)
                : 'invalid:'.$request->ip();

            return [
                Limit::perMinute(120)->by('connector:'.$connector),
                Limit::perMinute(1200)->by('ip:'.$request->ip()),
            ];
        };
        $limiter->for('link-poll', $linkLimits);
        $limiter->for('link-request', $linkLimits);
        Gate::before(
            fn (?User $user, string $ability) => app(PermissionAccess::class)->allows($user, $ability) ? true : null,
        );
        app('events')->listen(RouteMatched::class, [ExtensionConfiguration::class, 'matched']);
        app(ExtensionManager::class)->boot();
        config(['kraft.games' => app(GameRegistry::class)->all()]);
    }
}
