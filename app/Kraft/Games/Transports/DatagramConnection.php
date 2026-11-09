<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;

class DatagramConnection
{
    private float $deadline;

    public function __construct(private readonly mixed $stream, float $timeout = 5)
    {
        $this->deadline = microtime(true) + $timeout;
    }

    public function send(string $packet): void
    {
        if (strlen($packet) > 65507 || @fwrite($this->stream, $packet) !== strlen($packet)) {
            throw new TransportFailure('transports.incomplete_response');
        }
    }

    public function receive(): string
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0 || !is_resource($this->stream)) {
            throw new TransportFailure('transports.incomplete_response');
        }
        stream_set_timeout($this->stream, (int) $remaining, max(1, (int) (($remaining - floor($remaining)) * 1000000)));
        $read = [$this->stream];
        $write = [];
        $except = [];
        if (
            @stream_select(
                $read,
                $write,
                $except,
                (int) $remaining,
                max(1, (int) (($remaining - floor($remaining)) * 1000000)),
            ) !== 1
        ) {
            throw new TransportFailure('transports.incomplete_response');
        }
        $packet = @stream_socket_recvfrom($this->stream, 65535);
        if ($packet === false || $packet === '') {
            throw new TransportFailure('transports.incomplete_response');
        }

        return $packet;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
