<?php

namespace App\Http\Middleware;

use App\Kraft\Extensions\ExtensionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Symfony\Component\HttpFoundation\Response;

class ExtensionConfiguration
{
    public function matched(RouteMatched $event): void
    {
        $prefix = self::class.':';
        foreach ($event->route->middleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, $prefix)) {
                $this->assertAvailable(substr($middleware, strlen($prefix)), $event->request);
            }
        }
    }

    public function handle(Request $request, Closure $next, string $id): Response
    {
        $this->assertAvailable($id, $request);

        return $next($request);
    }

    private function assertAvailable(string $id, Request $request): void
    {
        $manager = app(ExtensionManager::class);
        $extension = $manager->all()[$id] ?? null;
        if (!$extension || !$manager->configurationAvailable($extension)) {
            $request->setRouteResolver(static fn () => null);
            abort(404);
        }
    }
}
