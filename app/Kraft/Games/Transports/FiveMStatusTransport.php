<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Closure;

class FiveMStatusTransport implements Transport
{
    public function __construct(private ServerHttpClient $http)
    {
    }

    public function metadata(): array
    {
        return [
            'status' => true,
            'commands' => false,
            'secret' => false,
            'fields' => [],
            'help' => 'admin.servers.transport.http_status_help',
        ];
    }

    public function status(Server $server): array
    {
        $started = microtime(true);
        $status = $this->http->json(
            $server,
            $server->port,
            false,
            'GET',
            '/dynamic.json',
            maxResponseBytes: 65536,
        );
        $online = filter_var($status['clients'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 1000000],
        ]);
        $maximum = filter_var($status['sv_maxclients'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 1000000],
        ]);
        if ($online === false || $maximum === false) {
            throw new TransportFailure('transports.invalid_response');
        }

        return [
            'players_online' => $online,
            'max_players' => $maximum,
            'version' => '',
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'transport' => $server->connection,
        ];
    }

    public function execute(Server $server, string $command, Closure $writing): void
    {
        throw new TransportFailure('transports.commands_unsupported', true);
    }
}
