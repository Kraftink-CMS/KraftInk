<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;

class SocketConnection
{
    private float $deadline;

    public function __construct(private readonly mixed $stream, float $timeout = 5)
    {
        $this->deadline = hrtime(true) / 1000000000 + $timeout;
        stream_set_blocking($stream, true);
    }

    public function read(int $length): string
    {
        if ($length < 0 || $length > 262144) {
            throw new TransportFailure('transports.invalid_response');
        }
        $data = '';
        while (strlen($data) < $length) {
            $this->timeout();
            $part = @fread($this->stream, $length - strlen($data));
            if ($part === false || $part === '') {
                throw new TransportFailure('transports.incomplete_response');
            }
            $data .= $part;
        }

        return $data;
    }

    public function write(string $data): void
    {
        if (strlen($data) > 262144) {
            throw new TransportFailure('transports.invalid_response');
        }
        $offset = 0;
        while ($offset < strlen($data)) {
            $this->timeout();
            $written = @fwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new TransportFailure('transports.incomplete_response');
            }
            $offset += $written;
        }
    }

    public function readable(float $timeout): bool
    {
        $left = min(max(0, $timeout), $this->deadline - hrtime(true) / 1000000000);
        if ($left <= 0 || !is_resource($this->stream)) {
            throw new TransportFailure('transports.incomplete_response');
        }
        $read = [$this->stream];
        $write = [];
        $except = [];
        $selected = @stream_select(
            $read,
            $write,
            $except,
            (int) $left,
            max(1, (int) (($left - floor($left)) * 1000000)),
        );
        if ($selected === false) {
            throw new TransportFailure('transports.incomplete_response');
        }

        return $selected > 0;
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

    private function timeout(): void
    {
        $left = $this->deadline - hrtime(true) / 1000000000;
        if ($left <= 0 || !is_resource($this->stream)) {
            throw new TransportFailure('transports.incomplete_response');
        }
        stream_set_timeout($this->stream, (int) $left, max(1, (int) (($left - floor($left)) * 1000000)));
    }
}
