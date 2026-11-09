<?php

namespace App\Kraft\Link;

use App\Kraft\Link\Contracts\PollHandler;
use App\Kraft\Link\Poll\ClaimCommands;
use App\Kraft\Link\Poll\Heartbeat;
use App\Kraft\Link\Poll\InGameSignIn;
use App\Kraft\System\MaintenancePolicy;
use App\Models\Servers\Server;
use InvalidArgumentException;

class LinkPollRegistry
{
    public const int MAX_OPERATIONS = 16;

    private const array CORE = [
        'heartbeat' => Heartbeat::class,
        'commands' => ClaimCommands::class,
        'in_game_sign_in' => InGameSignIn::class,
    ];

    private array $extensions = [];

    public function register(string $operation, string $handler): void
    {
        if (!LinkOperation::validExtensionName($operation) || !is_subclass_of($handler, PollHandler::class)) {
            throw new InvalidArgumentException('A link poll operation requires a namespaced key and a poll handler.');
        }
        if (isset($this->extensions[$operation]) && $this->extensions[$operation] !== $handler) {
            throw new InvalidArgumentException('Link poll operation is already registered: '.$operation);
        }
        $this->extensions[$operation] = $handler;
    }

    public function poll(Server $server, array $operations, string $path): array
    {
        $handlers = self::CORE + $this->extensions;
        $maintenance = app(MaintenancePolicy::class);
        $results = [];
        foreach ($operations as $operation => $payload) {
            $handler = $handlers[$operation] ?? null;
            if ($handler === null) {
                $results[$operation] = LinkOperation::failure(404);

                continue;
            }
            $legacyProtocol = in_array($operation, ['heartbeat', 'commands'], true);
            if (!$legacyProtocol && $maintenance->blocksPath($path)) {
                $results[$operation] = LinkOperation::failure(503);

                continue;
            }
            if (!is_array($payload)) {
                $results[$operation] = LinkOperation::failure(422);

                continue;
            }
            $payload = $legacyProtocol ? $payload + ['protocol' => 1] : $payload;
            $results[$operation] = LinkOperation::run(fn () => app($handler)->poll($server, $payload), !isset(self::CORE[$operation]));
        }

        return $results;
    }
}
