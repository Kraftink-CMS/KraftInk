<?php

namespace App\Http\Middleware;

use App\Kraft\Settings;
use App\Kraft\LocalePolicy;
use App\Kraft\TextTranslations;
use App\Kraft\System\MailConfiguration;
use App\Kraft\System\MaintenancePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Kraft\Setup\Installation;

class SystemContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!app(Installation::class)->installed() && !app()->runningUnitTests()) {
            return $next($request);
        }
        app(MailConfiguration::class)->apply();
        /** @var Settings $settings */
        $settings = app(Settings::class);
        if (app(MaintenancePolicy::class)->blocks($request)) {
            /** @var LocalePolicy $localePolicy */
            $localePolicy = app(LocalePolicy::class);
            $locale = $localePolicy->resolve($request, $request->user());
            /** @var TextTranslations $translations */
            $translations = app(TextTranslations::class);
            $message = $translations->resolve(
                $settings->get('maintenance.message', __('system.maintenance.default', [], $locale)),
                $settings->get('maintenance.translations', []),
                'message',
                null,
                $locale,
            );

            return $request->expectsJson()
                ? response()->json(['success' => false, 'data' => ['message' => $message]], 503, [
                    'Retry-After' => '3600',
                ])
                : response()->view('errors.maintenance', ['message' => $message], 503, ['Retry-After' => '3600']);
        }

        return $next($request);
    }
}
