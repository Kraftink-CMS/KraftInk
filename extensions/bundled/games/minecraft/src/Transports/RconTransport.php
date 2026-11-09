<?php

namespace KraftInk\Games\Minecraft\Transports;

use App\Kraft\Games\Transports\SocketTarget;
use App\Kraft\Games\Transports\SourceRconClient;
use App\Kraft\Games\Transports\Transport;
use App\Models\Servers\Server;
use Closure;

class RconTransport implements Transport
{
    private readonly SourceRconClient $client;

    public function __construct(SocketTarget $target, private StatusTransport $status)
    {
        $this->client = new SourceRconClient($target);
    }

    public function metadata(): array
    {
        return [
            'status' => true,
            'commands' => true,
            'secret' => true,
            'fields' => [
                'port' => [
                    'label' => 'admin.servers.transport.rcon_port',
                    'rules' => ['required', 'integer', 'between:1,65535'],
                    'type' => 'number',
                    'default' => 25575,
                    'min' => 1,
                    'max' => 65535,
                ],
            ],
            'help' => 'minecraft::messages.rcon_help',
        ];
    }

    public function status(Server $server): array
    {
        return $this->status->status($server);
    }

    public function execute(Server $server, string $command, Closure $writing): void
    {
        $this->client->exchange(
            $server->host,
            (int) ($server->transport_options['port'] ?? 25575),
            $server->secret,
            $command,
            $writing,
        );
    }
}
