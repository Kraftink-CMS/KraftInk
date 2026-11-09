<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use Symfony\Component\HttpFoundation\IpUtils;

class SocketTarget
{
    public function datagram(string $host, int $port): DatagramConnection
    {
        $address = $this->resolve($host, $port)[0];
        $target = 'udp://'.(str_contains($address, ':') ? '['.$address.']' : $address).':'.$port;
        $timeout = min(5, max(0.2, (float) config('games.network.connect_timeout', 2)));
        $stream = @stream_socket_client($target, $code, $message, $timeout);
        if (!is_resource($stream)) {
            throw new TransportFailure('transports.unreachable');
        }

        return new DatagramConnection($stream, min(10, max(1, (float) config('games.network.operation_timeout', 5))));
    }

    public function connect(string $host, int $port): SocketConnection
    {
        return $this->connectStream($host, $port, false);
    }

    public function connectTls(string $host, int $port): SocketConnection
    {
        return $this->connectStream($host, $port, true);
    }

    public function resolve(string $host, int $port): array
    {
        if ($port < 1 || $port > 65535 || strlen($host) > 253 || !preg_match('/^[a-zA-Z0-9.:-]+$/D', $host)) {
            throw new TransportFailure('transports.invalid_target', true);
        }
        if (
            !filter_var($host, FILTER_VALIDATE_IP) &&
            !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        ) {
            throw new TransportFailure('transports.invalid_target', true);
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->addresses($host);
        if (!$addresses || count($addresses) > 32) {
            throw new TransportFailure('transports.unreachable');
        }
        $allowed = config('games.network.private_cidrs', []);
        foreach ($addresses as $address) {
            if (
                !filter_var($address, FILTER_VALIDATE_IP) ||
                str_starts_with(strtolower($address), '::ffff:') ||
                (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) &&
                    !IpUtils::checkIp($address, $allowed))
            ) {
                throw new TransportFailure('transports.blocked_target', true);
            }
        }

        return array_values(array_unique($addresses));
    }

    public function addresses(string $host): array
    {
        return array_values(
            array_unique([
                ...@gethostbynamel($host) ?: [],
                ...array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            ]),
        );
    }

    private function connectStream(string $host, int $port, bool $tls): SocketConnection
    {
        $address = $this->resolve($host, $port)[0];
        $target = ($tls ? 'tls://' : 'tcp://').(str_contains($address, ':') ? '['.$address.']' : $address).':'.$port;
        $timeout = min(5, max(0.2, (float) config('games.network.connect_timeout', 2)));
        $context = $tls
            ? stream_context_create([
                'ssl' => [
                    'peer_name' => $host,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                ],
            ])
            : null;
        $stream = @stream_socket_client($target, $code, $message, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($stream)) {
            throw new TransportFailure($tls ? 'transports.tls_failed' : 'transports.unreachable');
        }
        if ($tls && !isset(stream_get_meta_data($stream)['crypto'])) {
            fclose($stream);

            throw new TransportFailure('transports.tls_failed');
        }

        return new SocketConnection($stream, min(10, max(1, (float) config('games.network.operation_timeout', 5))));
    }
}
