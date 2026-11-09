<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use Closure;
use Random\RandomException;

class SourceRconClient
{
    public function __construct(private readonly SocketTarget $target)
    {
    }

    public function exchange(
        string $host,
        int $port,
        mixed $secret,
        string $command,
        Closure $writing,
        bool $collect = false,
    ): string {
        if (
            !is_string($secret) ||
            $secret === '' ||
            strlen($secret) > 2048 ||
            str_contains($secret, "\x00") ||
            strlen($command) > 2048 ||
            preg_match('/[\x00\r\n]/', $command)
        ) {
            throw new TransportFailure('transports.authentication_failed', true);
        }
        $socket = $this->target->connect($host, $port);
        try {
            $id = random_int(1, 2147483645);
            $this->authenticate($socket, $id, $secret);
            $writing();
            $socket->write($this->packet($id + 1, 2, $command));

            return $this->response($socket, $id + 1, $collect);
        } catch (RandomException $exception) {
            throw new TransportFailure('transports.unavailable', previous: $exception);
        } finally {
            $socket->close();
        }
    }

    private function authenticate(SocketConnection $socket, int $id, string $secret): void
    {
        $socket->write($this->packet($id, 3, $secret));
        for ($index = 0; $index < 3; $index++) {
            $reply = $this->read($socket);
            if ($reply['id'] === 4294967295) {
                break;
            }
            if ($reply['id'] !== $id || !in_array($reply['type'], [0, 2], true)) {
                throw new TransportFailure('transports.invalid_response');
            }
            if ($reply['type'] === 2) {
                return;
            }
        }

        throw new TransportFailure('transports.authentication_failed', true);
    }

    private function response(SocketConnection $socket, int $id, bool $collect): string
    {
        $body = '';
        for ($index = 0; $index < 64; $index++) {
            $reply = $this->read($socket);
            if ($reply['type'] !== 0 || $reply['id'] !== $id || strlen($body) + strlen($reply['body']) > 131072) {
                throw new TransportFailure('transports.invalid_response');
            }
            $body .= $reply['body'];
            if (!$collect || !$socket->readable(0.5)) {
                return $body;
            }
        }

        throw new TransportFailure('transports.invalid_response');
    }

    private function packet(int $id, int $type, string $body): string
    {
        return pack('VVV', strlen($body) + 10, $id, $type).$body."\x00\x00";
    }

    private function read(SocketConnection $socket): array
    {
        $length = unpack('Vlength', $socket->read(4))['length'];
        if ($length < 10 || $length > 65536) {
            throw new TransportFailure('transports.invalid_response');
        }
        $data = $socket->read($length);
        if (!str_ends_with($data, "\x00\x00")) {
            throw new TransportFailure('transports.invalid_response');
        }

        return unpack('Vid/Vtype', substr($data, 0, 8)) + ['body' => substr($data, 8, -2)];
    }
}
