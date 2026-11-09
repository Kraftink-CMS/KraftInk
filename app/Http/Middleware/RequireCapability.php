<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Kraft\Site\ViewCapabilities;
use App\Kraft\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCapability
{
    public function handle(Request $request, Closure $next, string $capability, ?string $extension = null): Response
    {
        if ($capability === 'extension') {
            $allowed = $extension !== null && app(Features::class)->allows($extension, $request->user());
        } else {
            $capabilities = ViewCapabilities::forRequest($request, withExtensions: false);
            $allowed = $capabilities->allowsNative($capability);
        }
        if (!$allowed) {
            throw new NotFoundHttpException();
        }

        return $next($request);
    }
}
