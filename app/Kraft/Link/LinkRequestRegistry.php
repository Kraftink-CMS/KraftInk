<?php

namespace App\Kraft\Link;

use App\Kraft\Link\Contracts\RequestHandler;
use App\Models\Servers\Server;
use InvalidArgumentException;

class LinkRequestRegistry
{
    private array $handlers = [];

    public function register(string $operation, string $handler): void
    {
        if (!LinkOperation::validExtensionName($operation) || !is_subclass_of($handler, RequestHandler::class)) {
            throw new InvalidArgumentException('A link request operation requires a namespaced key and a request handler.');
        }
        if (isset($this->handlers[$operation]) && $this->handlers[$operation] !== $handler) {
            throw new InvalidArgumentException('Link request operation is already registered: '.$operation);
        }
        $this->handlers[$operation] = $handler;
    }

    public function handle(Server $server, string $operation, array $payload): array
    {
        $handler = $this->handlers[$operation] ?? null;
        abort_unless($handler, 404);

        return app($handler)->handle($server, $payload);
    }
}
