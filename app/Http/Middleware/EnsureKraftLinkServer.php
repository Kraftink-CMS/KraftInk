<?php

namespace App\Http\Middleware;

use App\Kraft\Games\GameRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKraftLinkServer
{
    public function handle(Request $request, Closure $next): Response
    {
        $server = $request->route('server');
        $games = app(GameRegistry::class);
        if (
            $server->game !== $games->activeId() ||
            $server->connection !== 'kraftlink' ||
            !isset($games->linkableGames()[$server->game]['connections']['kraftlink'])
        ) {
            abort(404);
        }

        return $next($request);
    }
}
