<?php

namespace App\Http\Middleware;

use App\Kraft\Site\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', Seo::NO_INDEX);

        return $response;
    }
}
