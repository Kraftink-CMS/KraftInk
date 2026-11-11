<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        return $request->is('admin', 'admin/*') ? $this->enforce($request, $next) : $next($request);
    }

    public function enforce(Request $request, Closure $next): Response
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        if (
            $request->user() &&
            $settings->get('security.admin_two_factor', false) &&
            !$request->user()->two_factor_confirmed_at
        ) {
            if ($request->expectsJson()) {
                throw new HttpException(403, __('admin.settings.security.two_factor_required'));
            }

            return response()->view('default.user.auth.admin_two_factor', [], 403);
        }

        return $next($request);
    }
}
