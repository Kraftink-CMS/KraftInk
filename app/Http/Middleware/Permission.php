<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Auth\PermissionAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Permission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!app(PermissionAccess::class)->allows($request->user(), $permission)) {
            throw new HttpException(403);
        }

        return $next($request);
    }
}
