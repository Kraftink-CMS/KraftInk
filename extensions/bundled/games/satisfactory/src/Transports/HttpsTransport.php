<?php

namespace KraftInk\Games\Satisfactory\Transports;

use App\Kraft\Games\Transports\ServerHttpClient;
use App\Kraft\Games\Transports\Transport;
use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Closure;

class HttpsTransport implements Transport
{
    public function __construct(private ServerHttpClient $http)
    {
    }

    public function metadata(): array
    {
        return [
            'status' => true,
            'commands' => true,
            'secret' => true,
            'secret_label' => 'satisfactory::messages.api_token',
            'help' => 'satisfactory::messages.api_help',
            'fields' => [
                'tls_certificate' => [
                    'type' => 'text',
                    'label' => 'admin.servers.transport.tls_certificate',
                    'help' => 'admin.servers.transport.tls_certificate_help',
                    'default' => '',
                    'maxlength' => 124,
                    'rules' => ['nullable', 'string', 'max:124', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}\.pem$/D'],
                ],
            ],
        ];
    }

    public function status(Server $server): array
    {
        $started = microtime(true);
        $data = $this->call($server, 'QueryServerState');
        $state = $data['serverGameState'] ?? ($data['ServerGameState'] ?? null);
        $online = $state['numConnectedPlayers'] ?? ($state['NumConnectedPlayers'] ?? null);
        $maximum = $state['playerLimit'] ?? ($state['PlayerLimit'] ?? null);
        if (
            !is_array($state) ||
            !is_int($online) ||
            !is_int($maximum) ||
            $online < 0 ||
            $maximum < $online ||
            $maximum > 1000000
        ) {
            throw new TransportFailure('transports.invalid_response');
        }

        return [
            'players_online' => $online,
            'max_players' => $maximum,
            'version' => '',
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'transport' => $server->connection,
        ];
    }

    public function execute(Server $server, string $command, Closure $writing): void
    {
        if (
            $command === '' ||
            strlen($command) > 2048 ||
            !mb_check_encoding($command, 'UTF-8') ||
            preg_match('/[\x00\r\n]/', $command)
        ) {
            throw new TransportFailure('transports.invalid_command', true);
        }
        $response = $this->http->request($server, $server->port, true, 'POST', '/api/v1', $this->headers($server), [
            'function' => 'VerifyAuthenticationToken',
        ]);
        if ($response->status() !== 204) {
            throw new TransportFailure('transports.authentication_failed', true);
        }
        $writing();
        $result = $this->call($server, 'RunCommand', ['Command' => $command]);
        if (($result['ReturnValue'] ?? ($result['returnValue'] ?? null)) !== true) {
            throw new TransportFailure('transports.invalid_response');
        }
    }

    private function call(Server $server, string $function, array $data = []): array
    {
        $response = $this->http->json($server, $server->port, true, 'POST', '/api/v1', $this->headers($server), [
            'function' => $function,
            'data' => (object) $data,
        ]);
        if (isset($response['errorCode']) || !is_array($response['data'] ?? null)) {
            throw new TransportFailure('transports.invalid_response');
        }

        return $response['data'];
    }

    private function headers(Server $server): array
    {
        if (!is_string($server->secret) || !preg_match('/^[a-zA-Z0-9._~+\/=-]{1,2048}$/D', $server->secret)) {
            throw new TransportFailure('transports.authentication_failed', true);
        }

        return ['Authorization' => 'Bearer '.$server->secret];
    }
}
