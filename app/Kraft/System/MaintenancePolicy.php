<?php

namespace App\Kraft\System;

use App\Http\Middleware\MaintenanceBypass;
use App\Kraft\Auth\PermissionAccess;
use App\Kraft\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MaintenancePolicy
{
    public function __construct(private Settings $settings)
    {
    }

    public function blocks(Request $request): bool
    {
        if (!$this->settings->get('maintenance.enabled', false)) {
            return false;
        }
        if (
            Str::is(
                [
                    'admin.*',
                    'install*',
                    'login',
                    'login.store',
                    'auth.logout',
                    'password.*',
                    'verification.*',
                    'auth.two_factor.*',
                    'auth.oauth.*',
                    'auth.registration.external.*',
                    'account.connections.store',
                    'account.connections.destroy',
                    'api.auth.*',
                    'auth.games.verify',
                ],
                $request->route()?->getName() ?? '',
            ) ||
            app(PermissionAccess::class)->allows($request->user(), 'admin.access') ||
            app(PermissionAccess::class)->allows($request->user(), 'maintenance.access')
        ) {
            return false;
        }
        if (
            $request->route() &&
            in_array(MaintenanceBypass::class, app('router')->gatherRouteMiddleware($request->route()), true)
        ) {
            return false;
        }

        return $this->blocksPath($request->decodedPath());
    }

    public function blocksPath(string $path): bool
    {
        $path = '/'.ltrim($path, '/');
        if (!$this->settings->get('maintenance.enabled', false)) {
            return false;
        }
        if (Str::is($this->settings->get('maintenance.exclusions', []), $path)) {
            return false;
        }

        return $this->settings->get('maintenance.mode', 'all') !== 'paths' ||
            Str::is($this->settings->get('maintenance.paths', []), $path);
    }

    public function normalizePattern(string $address, string $baseUrl = ''): ?string
    {
        if (preg_match('~[\x00-\x1F\x7F\\\\]~', $address)) {
            return null;
        }
        $parts = parse_url($address);
        if ($parts === false) {
            return null;
        }
        $absolute = isset($parts['scheme']);
        if (
            $absolute &&
            (!in_array(strtolower($parts['scheme']), ['http', 'https'], true) ||
                empty($parts['host']) ||
                preg_match('/\s/u', $parts['host']) ||
                isset($parts['user']) ||
                isset($parts['pass']))
        ) {
            return null;
        }
        if (!$absolute && (!str_starts_with($address, '/') || isset($parts['host']))) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        if (str_contains($path, '//')) {
            return null;
        }
        $baseUrl = rtrim($baseUrl, '/');
        if ($absolute && $baseUrl !== '' && ($path === $baseUrl || str_starts_with($path, $baseUrl.'/'))) {
            $path = substr($path, strlen($baseUrl));
        }
        $path = rawurldecode(rtrim($path, '/')) ?: '/';

        return $this->validPattern($path) ? $path : null;
    }

    public function validPattern(string $path): bool
    {
        return strlen($path) <= 300 &&
            preg_match('~^/(?!/)[\p{L}\p{M}\p{N}/_.*-]*$~uD', $path) &&
            !str_contains($path, '..') &&
            !str_contains($path, '//');
    }
}
