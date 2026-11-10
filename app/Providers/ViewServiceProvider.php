<?php

namespace App\Providers;

use App\Kraft\AdminNavigation;
use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Auth\Captcha;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\InstallationReceipts;
use App\Kraft\Extensions\ThemeContext;
use App\Kraft\SiteAnnouncements;
use App\Kraft\HomeBlocks;
use App\Kraft\Site\PublicViewContext;
use App\Kraft\Site\Seo;
use App\Kraft\Slider;
use App\Kraft\Updates\UpdateChannel;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

class ViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PublicViewContext::class);
    }

    public function boot(): void
    {
        view()->composer(['default.*', 'theme::*', '*::default.*'], function (View $view) {
            if (
                str_starts_with($view->name(), 'theme::') &&
                (app(ThemeContext::class)->current()['target'] ?? null) === 'admin'
            ) {
                return;
            }
            $view->with(app(PublicViewContext::class)->data(request()));
        });
        view()->composer('default.layouts.layout', function (View $view) {
            $sections = [];
            $factory = $view->getFactory();
            foreach (
                ['title', 'description', 'seo_title', 'seo_description', 'canonical', 'robots', 'og_type', 'og_image'] as $section
            ) {
                if ($factory->hasSection($section)) {
                    $sections[$section] = trim($factory->yieldContent($section));
                }
            }
            $data = $view->getData();
            $metadata = app(Seo::class)->metadata($sections, [
                'seoEntry' => $data['seoEntry'] ?? null,
                'page' => $data['seo'] ?? [],
                'variables' => $data['seoVariables'] ?? [],
            ]);
            if (str_starts_with($metadata['robots'], 'noindex,')) {
                request()->attributes->set(Seo::ROBOTS_ATTRIBUTE, $metadata['robots']);
            }
            $view->with('pageMetadata', $metadata);
        });
        view()->composer('admin.layouts.layout', function (View $view) {
            $administrator = auth()->user();
            $canUpdateCms = $administrator?->hasPermission('updates.manage') ?? false;
            $canUpdateExtensions = $administrator?->hasPermission('extensions.manage') ?? false;
            $availabilityEndpoint = null;
            $availabilityCacheKey = null;
            $availabilitySkip = '';
            if ($canUpdateCms || $canUpdateExtensions) {
                $availabilityEndpoint = route('admin.updates.availability');
                $availabilityCacheKey = hash(
                    'sha256',
                    json_encode(
                        [
                            $availabilityEndpoint,
                            $administrator->id,
                            $canUpdateCms,
                            $canUpdateExtensions,
                            config('manifest.version'),
                            PHP_VERSION,
                            app(UpdateChannel::class)->current(),
                            app(InstallationReceipts::class)->generation(),
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                );
                $availabilitySkip = match (true) {
                    request()->routeIs('admin.updates.index') => 'cms',
                    request()->routeIs('admin.extensions.plugins.index') => 'plugins',
                    request()->routeIs('admin.extensions.themes.index') => 'themes',
                    request()->routeIs('admin.games.index') => 'games',
                    default => '',
                };
            }
            $view->with([
                'administrator' => $administrator,
                'centralAnnouncements' => app(SiteAnnouncements::class)->active(),
                'navigationSections' => app(AdminNavigation::class)->build($administrator),
                'themeManifest' => app(ExtensionManager::class)->activeTheme('admin'),
                'adminDocumentTitle' => app(Seo::class)->formatTitle(
                    view()->yieldContent('title', __('common.labels.administration')),
                ),
                'canUpdateCms' => $canUpdateCms,
                'canUpdateExtensions' => $canUpdateExtensions,
                'availabilityEndpoint' => $availabilityEndpoint,
                'availabilityCacheKey' => $availabilityCacheKey,
                'availabilitySkip' => $availabilitySkip,
            ]);
        });
        view()->composer('default.user.auth.partials.registration_terms', function (View $view) {
            $terms = app(AccountPolicy::class)->terms();
            $view->with('terms', $terms)->with('customTerms', $terms['text'] || $terms['url']);
        });
        view()->composer('default.user.auth.partials.captcha', function (View $view) {
            $view->with('captcha', app(Captcha::class)->configuration($view->getData()['captchaAction']));
        });
        view()->composer('default.home.partials.welcome', function (View $view) {
            $view->with('welcome', $view->getData()['welcome'] ?? app(HomeBlocks::class)->content(request()->user()));
        });
        view()->composer('default.home.partials.slider', function (View $view) {
            $slider = app(Slider::class);
            $view->with(
                'slideControls',
                $view->getData()['slides']->mapWithKeys(fn ($slide) => [$slide->id => $slider->controls($slide)]),
            );
        });
    }
}
