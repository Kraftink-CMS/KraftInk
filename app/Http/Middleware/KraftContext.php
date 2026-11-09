<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Settings;
use App\Kraft\Site\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Kraft\Games\GameRegistry;
use App\Kraft\LocalePolicy;
use App\Kraft\Setup\Installation;
use Illuminate\Validation\ValidationException;

class KraftContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $installed = app(Installation::class)->installed() || app()->runningUnitTests();
        if (!$installed && !$request->is('install', 'install/*', 'up', 'build/*')) {
            return redirect('/install');
        }
        if (!$installed) {
            /** @var LocalePolicy $localePolicy */
            $localePolicy = app(LocalePolicy::class);
            app()->setLocale($localePolicy->resolve($request));

            return $next($request);
        }
        if ($request->user()?->isBanned() || $request->user()?->import_locked || $request->user()?->anonymized_at) {
            auth()->logout();
            $request->session()->invalidate();
            throw new HttpException(403, __('site.auth.messages.suspended'));
        }
        /** @var LocalePolicy $localePolicy */
        $localePolicy = app(LocalePolicy::class);
        app()->setLocale($localePolicy->resolve($request));
        /** @var Settings $settings */
        $settings = app(Settings::class);
        config([
            'app.name' => $settings->get('site.name', 'KraftInk'),
        ]);
        if ($request->user() && !$request->is('account*', 'oauth/*', 'logout', 'email/*', 'verify-email')) {
            if (app(AccountPolicy::class)->requiresVerifiedEmail() && !$request->user()->hasVerifiedEmail()) {
                return redirect('/verify-email');
            }
            try {
                app(GameRegistry::class)->assertUserCanAuthenticate($request->user());
            } catch (ValidationException $exception) {
                auth()->logout();
                $request->session()->invalidate();

                return redirect('/login')->withErrors($exception->errors());
            }
        }
        if ($request->user()?->force_password_reset && !$request->is('account*', 'logout')) {
            return redirect('/account')->withErrors(['password' => __('site.auth.messages.reset_required')]);
        }
        /** @var ApplyTheme $applyTheme */
        $applyTheme = app(ApplyTheme::class);
        $response = $applyTheme->handle($request, $next);
        $robots = $request->attributes->get(Seo::ROBOTS_ATTRIBUTE);
        if ($robots !== null && !$response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', $robots);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($response->headers->get('Referrer-Policy') !== 'no-referrer') {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
