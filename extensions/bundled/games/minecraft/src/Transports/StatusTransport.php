<?php

namespace KraftInk\Games\Minecraft\Transports;

use App\Kraft\Games\Transports\SocketConnection;
use App\Kraft\Games\Transports\SocketTarget;
use App\Kraft\Games\Transports\Transport;
use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Closure;
use JsonException;

class StatusTransport implements Transport
{
    public function __construct(private SocketTarget $target)
    {
    }

    public function metadata(): array
    {
        return [
            'status' => true,
            'commands' => false,
            'secret' => false,
            'fields' => [],
            'help' => 'minecraft::messages.status_help',
        ];
    }

    public function status(Server $server): array
    {
        $socket = $this->target->connect($server->host, $server->port);
        $started = microtime(true);
        try {
            $host = $server->host;
            $handshake =
                "\x00".
                $this->integer(4294967295).
                $this->integer(strlen($host)).
                $host.
                pack('n', $server->port).
                "\x01";
            $token = random_bytes(8);
            $socket->write($this->integer(strlen($handshake)).$handshake."\x01\x00\x09\x01".$token);
            $packet = $this->packet($socket);
            if ($this->decode($packet) !== 0) {
                throw new TransportFailure('transports.invalid_response');
            }
            $length = $this->decode($packet);
            if ($length !== strlen($packet) || $length > 131072) {
                throw new TransportFailure('transports.invalid_response');
            }
            $status = json_decode($packet, true, 32, JSON_THROW_ON_ERROR);
            $pong = $this->packet($socket);
            if (
                $this->decode($pong) !== 1 ||
                !hash_equals($token, $pong) ||
                !is_array($status) ||
                !is_int($status['players']['online'] ?? null) ||
                !is_int($status['players']['max'] ?? null)
            ) {
                throw new TransportFailure('transports.invalid_response');
            }

            return [
                'players_online' => max(0, min(1000000, $status['players']['online'])),
                'max_players' => max(0, min(1000000, $status['players']['max'])),
                'version' => mb_substr(
                    is_string($status['version']['name'] ?? null) ? $status['version']['name'] : '',
                    0,
                    100,
                ),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'transport' => $server->connection,
            ];
        } catch (JsonException) {
            throw new TransportFailure('transports.invalid_response');
        } finally {
            $socket->close();
        }
    }

    public function execute(Server $server, string $command, Closure $writing): void
    {
        throw new TransportFailure('transports.commands_unsupported', true);
    }

    private function packet(SocketConnection $socket): string
    {
        $value = 0;
        for ($index = 0; $index < 5; $index++) {
            $byte = ord($socket->read(1));
            $value |= ($byte & 127) << ($index * 7);
            if (($byte & 128) === 0) {
                if ($value < 1 || $value > 131072) {
                    break;
                }

                return $socket->read($value);
            }
        }
        throw new TransportFailure('transports.invalid_response');
    }

    private function decode(string &$value): int
    {
        $number = 0;
        for ($index = 0; $index < 5 && $value !== ''; $index++) {
            $byte = ord($value[0]);
            $value = substr($value, 1);
            $number |= ($byte & 127) << ($index * 7);
            if (($byte & 128) === 0) {
                return $number;
            }
        }
        throw new TransportFailure('transports.invalid_response');
    }

    private function integer(int $value): string
    {
        $encoded = '';
        do {
            $byte = $value & 127;
            $value >>= 7;
            $encoded .= chr($byte | ($value ? 128 : 0));
        } while ($value);

        return $encoded;
    }
}
