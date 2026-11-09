<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Closure;

class TelnetTransport implements Transport
{
    public function __construct(private SocketTarget $target, private SourceQueryTransport $query)
    {
    }

    public function metadata(): array
    {
        return array_replace_recursive($this->query->metadata(), [
            'commands' => true,
            'secret' => true,
            'help' => 'admin.servers.transport.telnet_help',
            'fields' => [
                'port' => [
                    'label' => 'admin.servers.transport.rcon_port',
                    'rules' => ['required', 'integer', 'between:1,65535'],
                    'type' => 'number',
                    'min' => 1,
                    'max' => 65535,
                    'default' => 8081,
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
        if (
            !$server->secret ||
            strlen($server->secret) > 2048 ||
            strlen($command) > 2048 ||
            preg_match('/[\x00\r\n\xff]/', $server->secret.$command)
        ) {
            throw new TransportFailure('transports.authentication_failed', true);
        }
        $socket = $this->target->connect($server->host, (int) ($server->transport_options['port'] ?? 8081));
        try {
            $this->until($socket, 'Please enter password:');
            $socket->write($server->secret."\r\n");
            $this->until($socket, 'Logon successful.');
            $writing();
            $socket->write($command."\r\n");
            $this->until($socket, "Executing command '".$command."'");
        } finally {
            $socket->close();
        }
    }

    private function until(SocketConnection $socket, string $expected): void
    {
        $response = '';
        for ($index = 0; $index < 65536; $index++) {
            $response .= $socket->read(1);
            if (str_contains($response, 'Wrong password') || str_contains($response, 'Password incorrect')) {
                throw new TransportFailure('transports.authentication_failed', true);
            }
            if (str_contains($response, $expected)) {
                return;
            }
        }
        throw new TransportFailure('transports.invalid_response');
    }
}
