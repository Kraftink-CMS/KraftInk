<?php

namespace KraftInk\Games\MinecraftBedrock\Transports;

use App\Exceptions\TransportFailure;
use App\Kraft\Games\Transports\SocketTarget;
use App\Kraft\Games\Transports\Transport;
use App\Models\Servers\Server;
use Closure;

class StatusTransport implements Transport
{
    private const string MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

    public function __construct(private readonly SocketTarget $target)
    {
    }

    public function metadata(): array
    {
        return [
            'status' => true,
            'commands' => false,
            'secret' => false,
            'help' => 'minecraftbedrock::messages.ping_help',
        ];
    }

    public function status(Server $server): array
    {
        $socket = $this->target->datagram($server->host, $server->port);
        $time = pack('J', (int) (microtime(true) * 1000));
        $started = hrtime(true);
        try {
            $socket->send("\x01".$time.self::MAGIC.random_bytes(8));
            $reply = $socket->receive();
        } finally {
            $socket->close();
        }

        if (strlen($reply) < 35 || !str_starts_with($reply, "\x1c".$time) || substr($reply, 17, 16) !== self::MAGIC) {
            throw new TransportFailure('transports.invalid_response');
        }
        $length = unpack('n', substr($reply, 33, 2))[1];
        $payload = substr($reply, 35);
        $fields = explode(';', $payload);
        if (
            $length !== strlen($payload) || $length > 4096 || !mb_check_encoding($payload, 'UTF-8') ||
            count($fields) < 6 || $fields[0] !== 'MCPE' ||
            !ctype_digit($fields[4]) || !ctype_digit($fields[5]) ||
            (int) $fields[4] > 1000000 || (int) $fields[5] > 1000000
        ) {
            throw new TransportFailure('transports.invalid_response');
        }

        return [
            'players_online' => (int) $fields[4],
            'max_players' => (int) $fields[5],
            'version' => mb_substr($fields[3], 0, 100),
            'latency_ms' => (int) round((hrtime(true) - $started) / 1000000),
            'transport' => $server->connection,
        ];
    }

    public function execute(Server $server, string $command, Closure $writing): void
    {
        throw new TransportFailure('transports.commands_unsupported', true);
    }
}
