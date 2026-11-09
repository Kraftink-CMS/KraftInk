<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

class ServerHttpClient
{
    public function __construct(private SocketTarget $target)
    {
    }

    /**
     * @throws ConnectionException
     */
    public function request(
        Server $server,
        int $port,
        bool $tls,
        string $method,
        string $path,
        array $headers = [],
        ?array $body = null,
        int $maxResponseBytes = 262144,
    ): Response {
        $addresses = $this->target->resolve($server->host, $port);
        if (!extension_loaded('curl')) {
            throw new TransportFailure('transports.unavailable');
        }
        if (!preg_match('~^/[a-zA-Z0-9/_-]+(?:\.json)?$~D', $path) || !in_array($method, ['GET', 'POST'], true)) {
            throw new TransportFailure('transports.invalid_target', true);
        }
        $host = str_contains($server->host, ':') ? '['.$server->host.']' : $server->host;
        $resolved = array_map(
            fn ($address) => str_contains($address, ':') ? '['.$address.']' : $address,
            $addresses,
        );
        $verify = true;
        $certificate = $server->transport_options['tls_certificate'] ?? '';
        if ($tls && $certificate !== '') {
            if (!is_string($certificate) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}\.pem$/D', $certificate)) {
                throw new TransportFailure('transports.invalid_target', true);
            }
            $root = realpath(storage_path('app/private/game-certificates'));
            $file = realpath(storage_path('app/private/game-certificates/'.$certificate));
            if (
                !$root ||
                !$file ||
                !is_file($file) ||
                is_link($file) ||
                !is_readable($file) ||
                dirname($file) !== $root
            ) {
                throw new TransportFailure('transports.invalid_target', true);
            }
            $verify = $file;
        }
        $response = Http::acceptJson()
            ->asJson()
            ->withHeaders($headers + ['Accept-Encoding' => 'identity'])
            ->connectTimeout(2)
            ->timeout(5)
            ->withOptions([
                'allow_redirects' => false,
                'decode_content' => false,
                'proxy' => '',
                'verify' => $verify,
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $resolved)]],
                'on_headers' => function ($response) use ($maxResponseBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxResponseBytes) {
                        throw new TransportFailure('transports.invalid_response');
                    }
                },
                'progress' => function ($total, $downloaded) use ($maxResponseBytes): void {
                    if ($downloaded > $maxResponseBytes) {
                        throw new TransportFailure('transports.invalid_response');
                    }
                },
            ])
            ->send(
                $method,
                ($tls ? 'https://' : 'http://').$host.':'.$port.$path,
                $body === null ? [] : ['json' => $body],
            );
        if (in_array($response->status(), [401, 403], true) && isset(array_change_key_case($headers)['authorization'])) {
            throw new TransportFailure('transports.authentication_failed', true);
        }
        if (!$response->successful() || strlen($response->body()) > $maxResponseBytes) {
            throw new TransportFailure('transports.invalid_response');
        }

        return $response;
    }

    /**
     * @throws ConnectionException
     */
    public function json(
        Server $server,
        int $port,
        bool $tls,
        string $method,
        string $path,
        array $headers = [],
        ?array $body = null,
        int $maxResponseBytes = 262144,
    ): array {
        $data = $this->request($server, $port, $tls, $method, $path, $headers, $body, $maxResponseBytes)->json();
        if (!is_array($data)) {
            throw new TransportFailure('transports.invalid_response');
        }

        return $data;
    }
}
