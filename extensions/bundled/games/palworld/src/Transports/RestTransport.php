<?php

namespace KraftInk\Games\Palworld\Transports;

use App\Kraft\Games\Transports\ServerHttpClient;
use App\Kraft\Games\Transports\Transport;
use App\Exceptions\TransportFailure;
use App\Kraft\Games\Transports\ProvidesOnlinePlayers;
use App\Models\Servers\Server;
use Closure;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\Palworld\Adapters\GameAdapter;

class RestTransport implements Transport, ProvidesOnlinePlayers
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
            'secret_label' => 'palworld::messages.admin_password',
            'help' => 'palworld::messages.rest_help',
            'fields' => [
                'api_port' => [
                    'type' => 'number',
                    'label' => 'palworld::messages.api_port',
                    'default' => 8212,
                    'min' => 1,
                    'max' => 65535,
                    'rules' => ['required', 'integer', 'min:1', 'max:65535'],
                ],
                'api_scheme' => [
                    'type' => 'select',
                    'label' => 'palworld::messages.api_encryption',
                    'options' => [
                        'http' => 'admin.servers.transport.encryption_unencrypted',
                        'https' => 'admin.servers.transport.encryption_tls',
                    ],
                    'default' => 'http',
                    'rules' => ['required', 'in:http,https'],
                ],
                'tls_certificate' => [
                    'type' => 'text',
                    'label' => 'admin.servers.transport.tls_certificate',
                    'help' => 'admin.servers.transport.tls_certificate_help',
                    'default' => '',
                    'maxlength' => 124,
                    'shown_when' => ['field' => 'api_scheme', 'value' => 'https'],
                    'rules' => [
                        'exclude_unless:transport_options.api_scheme,https',
                        'nullable',
                        'string',
                        'max:124',
                        'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}\.pem$/D',
                    ],
                ],
            ],
        ];
    }

    public function status(Server $server): array
    {
        $started = microtime(true);
        $info = $this->read($server, 'info');
        $metrics = $this->read($server, 'metrics');
        $online = $metrics['currentplayernum'] ?? null;
        $maximum = $metrics['maxplayernum'] ?? null;
        if (
            !is_int($online) ||
            !is_int($maximum) ||
            $online < 0 ||
            $maximum < $online ||
            $maximum > 1000000 ||
            !is_string($info['version'] ?? null) ||
            strlen($info['version']) > 100
        ) {
            throw new TransportFailure('transports.invalid_response');
        }

        return [
            'players_online' => $online,
            'max_players' => $maximum,
            'version' => $info['version'],
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
        [$action, $argument] = array_pad(explode(' ', $command, 2), 2, '');
        $body = match ($action) {
            'announce' => $argument !== '' ? ['message' => $argument] : null,
            'save' => $argument === '' ? [] : null,
            'kick', 'ban', 'unban' => GameAdapter::isValidUserId($argument) ? ['userid' => $argument] : null,
            default => null,
        };
        if ($body === null) {
            throw new TransportFailure('palworld::messages.unsupported_command', true);
        }
        $info = $this->read($server, 'info');
        if (!is_string($info['version'] ?? null) || $info['version'] === '' || strlen($info['version']) > 100) {
            throw new TransportFailure('transports.invalid_response');
        }
        $writing();
        $this->http->request(
            $server,
            $this->port($server),
            $this->tls($server),
            'POST',
            '/v1/api/'.$action,
            $this->headers($server),
            $body,
        );
    }

    public function onlinePlayers(Server $server): array
    {
        $data = $this->read($server, 'players');
        $players = $data['players'] ?? null;
        if (!is_array($players) || !array_is_list($players) || count($players) > 4096) {
            throw new TransportFailure('transports.invalid_response');
        }
        $adapter = app(GameAdapter::class);
        $ids = [];
        foreach ($players as $player) {
            if (!is_array($player) || !is_string($player['userId'] ?? null)) {
                throw new TransportFailure('transports.invalid_response');
            }
            try {
                $ids[] = $adapter->normalizeUuid($player['userId']);
            } catch (ValidationException) {
                throw new TransportFailure('transports.invalid_response');
            }
        }

        return array_values(array_unique($ids));
    }

    private function read(Server $server, string $path): array
    {
        return $this->http->json(
            $server,
            $this->port($server),
            $this->tls($server),
            'GET',
            '/v1/api/'.$path,
            $this->headers($server),
        );
    }

    private function port(Server $server): int
    {
        return (int) ($server->transport_options['api_port'] ?? 8212);
    }

    private function tls(Server $server): bool
    {
        $scheme = $server->transport_options['api_scheme'] ?? 'http';
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new TransportFailure('transports.invalid_target', true);
        }

        return $scheme === 'https';
    }

    private function headers(Server $server): array
    {
        if (!is_string($server->secret) || $server->secret === '') {
            throw new TransportFailure('transports.authentication_failed', true);
        }

        return ['Authorization' => 'Basic '.base64_encode('admin:'.$server->secret)];
    }
}
