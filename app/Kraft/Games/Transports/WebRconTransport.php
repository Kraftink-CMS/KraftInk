<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Closure;
use JsonException;
use Random\RandomException;

class WebRconTransport implements Transport
{
    public function __construct(private SocketTarget $target, private SourceQueryTransport $query)
    {
    }

    public function metadata(): array
    {
        $query = $this->query->metadata();

        return array_replace($query, [
            'commands' => true,
            'secret' => true,
            'help' => 'admin.servers.transport.web_rcon_help',
            'fields' => [
                'scheme' => [
                    'label' => 'admin.servers.transport.web_rcon_encryption',
                    'help' => 'admin.servers.transport.web_rcon_encryption_help',
                    'rules' => ['required', 'string', 'in:ws,wss'],
                    'type' => 'select',
                    'options' => [
                        'ws' => 'admin.servers.transport.encryption_unencrypted',
                        'wss' => 'admin.servers.transport.encryption_tls',
                    ],
                    'default' => 'ws',
                ],
                'host' => [
                    'label' => 'admin.servers.transport.web_rcon_host',
                    'help' => 'admin.servers.transport.web_rcon_host_help',
                    'rules' => ['nullable', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.:-]+$/D'],
                    'type' => 'text',
                    'maxlength' => 253,
                ],
                'port' => [
                    'label' => 'admin.servers.transport.rcon_port',
                    'rules' => ['required', 'integer', 'between:1,65535'],
                    'type' => 'number',
                    'min' => 1,
                    'max' => 65535,
                    'default' => 28016,
                ],
            ] + $query['fields'],
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
            preg_match('/[\x00\r\n]/', $server->secret.$command) ||
            strlen($command) > 2048
        ) {
            throw new TransportFailure('transports.authentication_failed', true);
        }
        $options = ($server->transport_options ?? []) + ['scheme' => 'ws'];
        $host = $options['host'] ?? $server->host;
        $port = (int) ($options['port'] ?? 28016);
        $socket = match ($options['scheme']) {
            'ws' => $this->target->connect($host, $port),
            'wss' => $this->target->connectTls($host, $port),
            default => throw new TransportFailure('transports.invalid_target', true),
        };
        try {
            $key = base64_encode(random_bytes(16));
            $authority = str_contains($host, ':') ? '['.$host.']' : $host;
            $socket->write(
                'GET /'.
                    rawurlencode($server->secret).
                    " HTTP/1.1\r\nHost: ".
                    $authority.
                    ':'.
                    $port.
                    "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: ".
                    $key.
                    "\r\n\r\n",
            );
            $headers = '';
            while (!str_ends_with($headers, "\r\n\r\n") && strlen($headers) < 8192) {
                $headers .= $socket->read(1);
            }
            if (preg_match('~^HTTP/1\.[01] (?:401|403)\b~', $headers)) {
                throw new TransportFailure('transports.authentication_failed', true);
            }
            $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            if (
                !preg_match('~^HTTP/1\.[01] 101\b~', $headers) ||
                !preg_match('/\r\nSec-WebSocket-Accept:\s*'.preg_quote($accept, '/').'\s*\r\n/i', $headers)
            ) {
                throw new TransportFailure('transports.invalid_response');
            }
            $id = random_int(1, 2147483647);
            $payload = json_encode(
                ['Identifier' => $id, 'Message' => $command, 'Name' => 'KraftInk'],
                JSON_THROW_ON_ERROR,
            );
            $frame = $this->frame($payload);
            $writing();
            $socket->write($frame);
            $message = '';
            for ($attempt = 0; $attempt < 64; $attempt++) {
                [$opcode, $final, $body] = $this->readFrame($socket);
                if ($opcode === 9) {
                    $socket->write($this->frame($body, 10));

                    continue;
                }
                if ($opcode === 10) {
                    continue;
                }
                if (!in_array($opcode, [0, 1], true) || ($opcode === 0 && $message === '')) {
                    throw new TransportFailure('transports.invalid_response');
                }
                $message .= $body;
                if (strlen($message) > 65536) {
                    throw new TransportFailure('transports.invalid_response');
                }
                if (!$final) {
                    continue;
                }
                $reply = json_decode($message, true);
                if (!is_array($reply)) {
                    throw new TransportFailure('transports.invalid_response');
                }
                if (($reply['Identifier'] ?? null) === $id) {
                    return;
                }
                $message = '';
            }
            throw new TransportFailure('transports.invalid_response');
        } catch (RandomException $exception) {
            throw new TransportFailure('transports.unavailable', previous: $exception);
        } catch (JsonException $exception) {
            throw new TransportFailure('transports.invalid_command', true, $exception);
        } finally {
            $socket->close();
        }
    }

    /**
     * @throws RandomException
     */
    private function frame(string $body, int $opcode = 1): string
    {
        $length = strlen($body);
        $mask = random_bytes(4);
        $encoded = '';
        for ($index = 0; $index < $length; $index++) {
            $encoded .= $body[$index] ^ $mask[$index % 4];
        }

        return chr(128 | $opcode).
            ($length < 126 ? chr(128 | $length) : "\xfe".pack('n', $length)).
            $mask.
            $encoded;
    }

    private function readFrame(SocketConnection $socket): array
    {
        $header = $socket->read(2);
        $opcode = ord($header[0]) & 15;
        $length = ord($header[1]) & 127;
        if (
            ord($header[0]) & 112 ||
            ord($header[1]) & 128 ||
            $length === 127 ||
            ($opcode >= 8 && (!(ord($header[0]) & 128) || $length > 125))
        ) {
            throw new TransportFailure('transports.invalid_response');
        }
        if ($length === 126) {
            $length = unpack('nlength', $socket->read(2))['length'];
        }

        return [$opcode, (bool) (ord($header[0]) & 128), $socket->read($length)];
    }
}
