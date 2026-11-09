<?php

namespace App\Http\Middleware;

use App\Kraft\Extensions\ThemeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(ThemeContext::class);

        return $context->run($context->target($request), fn () => $next($request));
    }
}
