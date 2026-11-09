<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

class TrustProxies
{
    public function handle(Request $request, Closure $next): Response
    {
        Request::setTrustedProxies(
            $this->proxies(),
            SymfonyRequest::HEADER_X_FORWARDED_FOR |
                SymfonyRequest::HEADER_X_FORWARDED_PROTO |
                SymfonyRequest::HEADER_X_FORWARDED_PORT,
        );

        return $next($request);
    }

    protected function proxies(): array
    {
        return [];
    }
}
