<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Closure;

class SourceRconTransport implements Transport
{
    protected const ?int DEFAULT_RCON_PORT = null;

    private readonly SourceRconClient $client;

    public function __construct(SocketTarget $target, private readonly SourceQueryTransport $query)
    {
        $this->client = new SourceRconClient($target);
    }

    public function metadata(): array
    {
        return array_replace_recursive($this->query->metadata(), [
            'commands' => true,
            'secret' => true,
            'help' => 'admin.servers.transport.rcon_help',
            'fields' => [
                'port' => [
                    'label' => 'admin.servers.transport.rcon_port',
                    'rules' => ['required', 'integer', 'between:1,65535'],
                    'type' => 'number',
                    'min' => 1,
                    'max' => 65535,
                    'default' => static::DEFAULT_RCON_PORT ?? 27015,
                ],
            ],
        ]);
    }

    public function status(Server $server): array
    {
        return $this->query->status($server);
    }

    public function execute(Server $server, string $command, Closure $writing): void
    {
        $this->exchange($server, $command, $writing);
    }

    public function exchange(Server $server, string $command, Closure $writing, bool $collect = false): string
    {
        $secret = $server->secret;
        if (is_string($secret) && preg_match('/[\r\n]/', $secret)) {
            throw new TransportFailure('transports.authentication_failed', true);
        }

        return $this->client->exchange(
            $server->host,
            (int) ($server->transport_options['port'] ?? static::DEFAULT_RCON_PORT ?? $server->port),
            $secret,
            $command,
            $writing,
            $collect,
        );
    }
}
