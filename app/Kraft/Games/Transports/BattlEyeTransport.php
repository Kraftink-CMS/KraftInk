<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Closure;

class BattlEyeTransport implements Transport
{
    public function __construct(private SocketTarget $target, private SourceQueryTransport $query)
    {
    }

    public function metadata(): array
    {
        return array_replace_recursive($this->query->metadata(), [
            'commands' => true,
            'secret' => true,
            'help' => 'admin.servers.transport.battleye_help',
            'fields' => [
                'port' => [
                    'label' => 'admin.servers.transport.rcon_port',
                    'rules' => ['required', 'integer', 'between:1,65535'],
                    'type' => 'number',
                    'min' => 1,
                    'max' => 65535,
                    'default' => 2305,
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
            preg_match('/[^\x20-\x7e]/', $server->secret.$command) ||
            strlen($command) > 2048
        ) {
            throw new TransportFailure('transports.authentication_failed', true);
        }
        $socket = $this->target->datagram($server->host, (int) ($server->transport_options['port'] ?? 2305));
        try {
            $socket->send($this->packet("\x00".$server->secret));
            if ($this->receive($socket, 0) !== "\x00\x01") {
                throw new TransportFailure('transports.authentication_failed', true);
            }
            $writing();
            $socket->send($this->packet("\x01\x00".$command));
            $parts = [];
            $total = null;
            for ($index = 0; $index < 64; $index++) {
                $reply = $this->receive($socket, 1);
                if (strlen($reply) < 2 || $reply[1] !== "\x00") {
                    throw new TransportFailure('transports.invalid_response');
                }
                if (strlen($reply) < 3 || $reply[2] !== "\x00") {
                    return;
                }
                if (
                    strlen($reply) < 5 ||
                    ord($reply[3]) < 1 ||
                    ord($reply[3]) > 32 ||
                    ord($reply[4]) >= ord($reply[3]) ||
                    ($total !== null && $total !== ord($reply[3]))
                ) {
                    throw new TransportFailure('transports.invalid_response');
                }
                $total = ord($reply[3]);
                $parts[ord($reply[4])] = true;
                if (count($parts) === $total) {
                    return;
                }
            }
            throw new TransportFailure('transports.invalid_response');
        } finally {
            $socket->close();
        }
    }

    private function receive(DatagramConnection $socket, int $type): string
    {
        for ($index = 0; $index < 32; $index++) {
            $packet = $socket->receive();
            if (
                strlen($packet) < 8 ||
                substr($packet, 0, 2) !== 'BE' ||
                $packet[6] !== "\xff" ||
                substr($packet, 2, 4) !== pack('V', crc32(substr($packet, 6)))
            ) {
                throw new TransportFailure('transports.invalid_response');
            }
            $payload = substr($packet, 7);
            if ($payload[0] === "\x02" && strlen($payload) >= 2) {
                $socket->send($this->packet(substr($payload, 0, 2)));

                continue;
            }
            if (ord($payload[0]) !== $type) {
                throw new TransportFailure('transports.invalid_response');
            }

            return $payload;
        }
        throw new TransportFailure('transports.invalid_response');
    }

    private function packet(string $payload): string
    {
        $data = "\xff".$payload;

        return 'BE'.pack('V', crc32($data)).$data;
    }
}
