<?php

namespace App\Http\Middleware;

use App\Kraft\Auth\Captcha;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCaptcha
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        app(Captcha::class)->verify($request, $action);

        return $next($request);
    }
}
