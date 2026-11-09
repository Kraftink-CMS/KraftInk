<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Closure;
use xPaw\SourceQuery\Exception\SourceQueryException;
use xPaw\SourceQuery\SourceQuery;

class SourceQueryTransport implements Transport
{
    protected const ?int DEFAULT_QUERY_PORT = null;

    public function __construct(private readonly SocketTarget $target)
    {
    }

    public function metadata(): array
    {
        return [
            'status' => true,
            'commands' => false,
            'secret' => false,
            'fields' => [
                'query_port' => [
                    'label' => 'admin.servers.transport.query_port',
                    'help' => 'admin.servers.transport.query_port_help',
                    'rules' => ['nullable', 'integer', 'between:1,65535'],
                    'type' => 'number',
                    'min' => 1,
                    'max' => 65535,
                    'default' => static::DEFAULT_QUERY_PORT,
                ],
            ],
            'help' => 'admin.servers.transport.query_help',
        ];
    }

    public function status(Server $server): array
    {
        $started = microtime(true);
        $port = (int) ($server->transport_options['query_port'] ?? static::DEFAULT_QUERY_PORT ?? $server->port);
        $query = new SourceQuery(new SourceQuerySocket($this->target));
        try {
            $query->Connect($server->host, $port);
            $info = $query->GetInfo();
        } catch (SourceQueryException $failure) {
            throw new TransportFailure('transports.invalid_response', previous: $failure);
        } finally {
            $query->Disconnect();
        }

        return [
            'players_online' => $info['Players'],
            'max_players' => $info['MaxPlayers'],
            'version' => mb_substr(mb_convert_encoding($info['Version'], 'UTF-8', 'UTF-8'), 0, 100),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'transport' => $server->connection,
        ];
    }

    public function execute(Server $server, string $command, Closure $writing): void
    {
        throw new TransportFailure('transports.commands_unsupported', true);
    }
}
