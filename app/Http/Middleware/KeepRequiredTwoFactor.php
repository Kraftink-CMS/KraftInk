<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class KeepRequiredTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        if ($request->user()?->hasPermission('admin.access') && $settings->get('security.admin_two_factor', false)) {
            throw new HttpException(403, __('admin.settings.security.two_factor_cannot_disable'));
        }

        return $next($request);
    }
}
