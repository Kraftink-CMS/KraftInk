<?php

namespace App\Http\Middleware;

use App\Kraft\Redirects;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolveRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (NotFoundHttpException|ModelNotFoundException $exception) {
            /** @var Redirects $redirects */
            $redirects = app(Redirects::class);

            return $redirects->resolve($request) ?? throw $exception;
        }

        if ($response->getStatusCode() !== 404) {
            return $response;
        }

        /** @var Redirects $redirects */
        $redirects = app(Redirects::class);

        return $redirects->resolve($request) ?? $response;
    }
}
