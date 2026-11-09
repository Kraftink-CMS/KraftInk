<?php

namespace App\Providers;

use App\Kraft\Extensions\ThemeConfiguration;
use App\Kraft\Extensions\ThemeContext;
use App\Exceptions\ThemeExceptionHandler;
use App\Mail\Theme\ThemeMailManager;
use App\Mail\Theme\ThemeMarkdown;
use App\Kraft\Extensions\ThemePreview;
use App\Kraft\Extensions\ThemeTemplates;
use App\Kraft\Site\SiteIdentity;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Mail\Markdown;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Traits\Localizable;
use Illuminate\View\View;

class ThemeServiceProvider extends ServiceProvider
{
    use Localizable;

    public function register(): void
    {
        $this->app->scoped(ThemeContext::class);
        $this->app->scoped(ThemeConfiguration::class);
        $this->app->scoped(ThemePreview::class);
        $this->app->singleton(ExceptionHandler::class, ThemeExceptionHandler::class);
        $this->app->extend('mail.manager', fn () => new ThemeMailManager($this->app));
        $this->app->extend(
            Markdown::class,
            fn (Markdown $markdown) => new ThemeMarkdown($this->app['view'], [
                'theme' => $markdown->getTheme(),
                'paths' => $this->app['config']->get('mail.markdown.paths', []),
                'extensions' => $this->app['config']->get('mail.markdown.extensions', []),
            ]),
        );
    }

    public function boot(): void
    {
        view()->composer(['emails.layouts.base', 'emails.layouts.text'], function (View $view): void {
            $locale = $view->getData()['locale'] ?? app()->getLocale();
            $view->with('mailLocale', str_replace('_', '-', $locale));
            $view->with('mailSiteName', $this->withLocale($locale, fn () => app(SiteIdentity::class)->text('name')));
            $view->with('mailFooter', __('site.auth.mail.footer', [
                'site' => $view->getData()['mailSiteName'],
            ], $locale));
        });
        view()->composer(['default.layouts.layout', 'admin.layouts.layout'], function (View $view): void {
            $theme = app(ThemeContext::class)->current();
            if (!$theme) {
                return;
            }
            $factory = $view->getFactory();
            foreach (['styles' => 'head', 'scripts' => 'scripts'] as $kind => $stack) {
                $once = 'theme.assets.'.$theme['id'].'.'.$kind;
                if (empty($theme['assets'][$kind]) || $factory->hasRenderedOnce($once)) {
                    continue;
                }
                $factory->markAsRenderedOnce($once);
                $markup = $factory->make('partials.layout.theme-assets', [
                    'themeManifest' => $theme,
                    'kind' => $kind,
                ])->render();
                if ($markup !== '') {
                    $factory->startPrepend($stack, $markup);
                }
            }
        });
        view()->creator('*', function (View $view): void {
            if ($theme = app(ThemeContext::class)->current()) {
                app(ThemeTemplates::class)->apply($view, $theme);
            }
        });
        view()->composer('*', function (View $view): void {
            $theme = app(ThemeContext::class)->current();
            $view->with('themeSettings', $theme ? app(ThemeConfiguration::class)->values($theme) : []);
        });
    }
}
