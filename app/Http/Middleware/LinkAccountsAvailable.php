<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Setup\Installation;
use App\Kraft\LocalePolicy;
use App\Kraft\Migration\ImportManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LinkAccountsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!(app(Installation::class)->installed() || app()->runningUnitTests())) {
            throw new HttpException(503);
        }
        if (app(ImportManager::class)->isRunning()) {
            throw new HttpException(503);
        }
        app()->setLocale(app(LocalePolicy::class)->defaultLocale());
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
