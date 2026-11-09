<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Auth\ApiTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Kraft\LocalePolicy;
use JsonException;

class AuthenticateApiToken
{
    /**
     * @throws JsonException
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $raw =
            $request->bearerToken() ??
            ($request->isMethod('post')
                ? ($request->isJson()
                    ? $request->json('access_token')
                    : $request->request->get('access_token'))
                : null);
        if (!(is_string($raw) && strlen($raw) <= 120)) {
            throw new HttpException(401, __('admin.integrations.auth_api.invalid_token'));
        }
        [$token, $user] = app(ApiTokens::class)->authenticate($raw, $scope);
        $request->attributes->set('auth.api_token', $token);
        $request->setUserResolver(fn () => $user);
        /** @var LocalePolicy $localePolicy */
        $localePolicy = app(LocalePolicy::class);
        app()->setLocale($localePolicy->resolve($request, $user));

        return $next($request);
    }
}
