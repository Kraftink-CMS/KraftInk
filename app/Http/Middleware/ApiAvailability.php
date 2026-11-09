<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Kraft\LocalePolicy;

class ApiAvailability
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var LocalePolicy $localePolicy */
        $localePolicy = app(LocalePolicy::class);
        app()->setLocale($localePolicy->resolve($request));
        /** @var Settings $settings */
        $settings = app(Settings::class);
        if (!$settings->get('auth.api.enabled', false)) {
            throw new HttpException(403, __('admin.integrations.auth_api.disabled'));
        }
        if (
            !(
                $request->isSecure() ||
                app()->environment(['local', 'testing']) ||
                in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true)
            )
        ) {
            throw new HttpException(426, __('admin.integrations.auth_api.https_required'));
        }

        return $next($request)->withHeaders(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
    }
}
