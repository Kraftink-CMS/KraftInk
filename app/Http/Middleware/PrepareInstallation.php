<?php

namespace App\Http\Middleware;

use App\Kraft\Setup\Installation;
use App\Kraft\LocalePolicy;
use App\Kraft\Setup\InstallationAccess;
use App\Kraft\Setup\InstallationFiles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class PrepareInstallation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(Installation::class)->installed()) {
            $cookie = $request->cookies->has(InstallationAccess::COOKIE);
            $response = $next($request);
            if ($cookie) {
                $response->headers->clearCookie(
                    InstallationAccess::COOKIE,
                    $request->getBasePath() ?: '/',
                    secure: $request->isSecure(),
                    sameSite: Cookie::SAMESITE_STRICT,
                );
            }

            return $response;
        }
        if (app()->runningUnitTests()) {
            return $next($request);
        }
        if (!$request->is('up') && !$request->attributes->has('installation.access')) {
            app()->setLocale(app(LocalePolicy::class)->installationLocale($request));
            $cookie = $request->cookies->get(InstallationAccess::COOKIE);
            try {
                $token = app(InstallationAccess::class)->authorize(
                    (string) $request->ip(),
                    is_string($cookie) ? $cookie : null,
                    $request->isMethod('GET'),
                );
            } catch (RuntimeException $exception) {
                report($exception);

                return $this->blocked(true);
            }
            if ($token === null) {
                return $this->blocked();
            }
            $request->attributes->set('installation.access', $token);
        }
        config(['session.driver' => 'file', 'cache.default' => 'file']);
        if ($request->hasSession()) {
            foreach (array_keys($request->session()->all()) as $key) {
                if (str_starts_with($key, 'login_') || str_starts_with($key, 'password_hash_')) {
                    $request->session()->forget($key);
                }
            }
            $request->session()->forget(['auth', 'twofactor']);
            foreach (array_keys($request->cookies->all()) as $key) {
                if (str_starts_with($key, 'remember_')) {
                    $request->cookies->remove($key);
                }
            }
            Auth::forgetGuards();
        }
        $request->setUserResolver(fn () => null);
        if (!$request->is('install', 'install/*', 'up')) {
            $response = redirect('/install');
        } else {
            $response = $next($request);
        }
        if (app(Installation::class)->installed()) {
            $response->headers->clearCookie(
                InstallationAccess::COOKIE,
                $request->getBasePath() ?: '/',
                secure: $request->isSecure(),
                sameSite: Cookie::SAMESITE_STRICT,
            );
        } elseif (isset($token)) {
            $response->headers->setCookie(
                Cookie::create(
                    InstallationAccess::COOKIE,
                    $token,
                    time() + 31536000,
                    $request->getBasePath() ?: '/',
                    null,
                    $request->isSecure(),
                    true,
                    false,
                    Cookie::SAMESITE_STRICT,
                ),
            );
        }
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function blocked(bool $unavailable = false): Response
    {
        return response()->view(
            'install.protected',
            [
                'recoveryFile' => InstallationFiles::ACCESS,
                'unavailable' => $unavailable,
                'installationProtected' => true,
            ],
            $unavailable ? 503 : 403,
            ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow'],
        );
    }
}
