<?php

namespace App\Http\Middleware;

use App\Kraft\System\OperationLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Session\NullSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use App\Exceptions\OperationBusy;

class OperationGuard
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if ($request->attributes->has('operations.lease')) {
            return $next($request);
        }
        $middleware = $request->route()?->gatherMiddleware() ?? [];
        $lock = app(OperationLock::class);
        $lease = $lock->acquireBootstrap(in_array('operation:exclusive', $middleware, true));
        $request->attributes->set('operations.lease', $lease);
        $request->attributes->set('operations.exclusive', in_array('operation:exclusive', $middleware, true));
        $request->attributes->set('operations.launch', in_array('operation:launch', $middleware, true));
        try {
            return $next($request);
        } finally {
            $request->attributes->get('operations.lease')?->release();
        }
    }

    public static function beforeWorker(bool $extensionOnly = false): void
    {
        if (!app()->bound('request')) {
            return;
        }
        $request = request();
        if (!$request->attributes->get('operations.launch', false)) {
            return;
        }
        if ($request->hasSession() && !$request->attributes->get('operations.detached')) {
            $request->session()->save();
            $request->session()->setHandler(new NullSessionHandler());
        }
        $request->attributes->set('operations.allow_reads', $extensionOnly);
        if (!$request->attributes->get('operations.detached')) {
            $guard = function (string $query) use ($request): void {
                if (
                    app()->bound('request') &&
                    request() === $request &&
                    $request->attributes->get('operations.detached') &&
                    (!$request->attributes->get('operations.allow_reads') ||
                        !preg_match('/^\s*select\b/i', $query) ||
                        app(OperationLock::class)->idle())
                ) {
                    throw new OperationBusy();
                }
            };
            foreach (DB::getConnections() as $connection) {
                $connection->beforeExecuting($guard);
            }
            Event::listen(ConnectionEstablished::class, fn ($event) => $event->connection->beforeExecuting($guard));
        }
        $request->attributes->get('operations.lease')?->release();
        $request->attributes->set('operations.generation', app(OperationLock::class)->lastGeneration());
        $request->attributes->set('operations.detached', true);
        app(OperationLock::class)->retireRequest();
    }

    public static function staging(Closure $operation): mixed
    {
        if (!app()->bound('request') || !request()->attributes->get('operations.detached')) {
            return $operation();
        }
        if (!request()->attributes->get('operations.allow_reads')) {
            throw new OperationBusy();
        }
        $lease = app(OperationLock::class)->acquireStaging(request()->attributes->get('operations.generation', ''));
        request()->attributes->set('operations.lease', $lease);
        try {
            return $operation();
        } finally {
            $lease->release();
        }
    }

    public static function workerFinished(array $state): void
    {
        if (
            app()->bound('request') &&
            request()->attributes->get('operations.detached') &&
            is_string($state['generation'] ?? null) &&
            preg_match('/^[a-f0-9]{64}$/D', $state['generation'])
        ) {
            request()->attributes->set('operations.generation', $state['generation']);
        }
    }
}
