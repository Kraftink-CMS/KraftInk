<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Link\ConnectorInstance;
use App\Kraft\Migration\ImportManager;
use App\Models\Servers\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Setup\Installation;

class AuthenticateLink
{
    public function __construct(private ConnectorInstance $connector)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(app(Installation::class)->installed() || app()->runningUnitTests())) {
            throw new HttpException(503);
        }
        if (app(ImportManager::class)->isRunning()) {
            throw new HttpException(503);
        }
        $token = $request->bearerToken();
        if (!(is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token))) {
            throw new HttpException(401);
        }
        $server = Server::linkedKraftLink()
            ->where('token_hash', hash('sha256', $token))
            ->first();
        if ($server === null) {
            throw new HttpException(401);
        }
        $games = app(GameRegistry::class)->linkableGames();
        if (!isset($games[$server->game]['connections']['kraftlink'])) {
            throw new HttpException(403);
        }
        $this->connector->confirm($request, $server);
        $request->attributes->set('link.server', $server);

        return $next($request);
    }
}
