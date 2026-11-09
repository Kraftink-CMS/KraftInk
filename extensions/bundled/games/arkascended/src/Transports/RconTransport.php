<?php

namespace KraftInk\Games\ArkAscended\Transports;

use App\Kraft\Games\Transports\SourceRconTransport;
use App\Kraft\Games\Transports\ProvidesOnlinePlayers;
use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;

class RconTransport extends SourceRconTransport implements ProvidesOnlinePlayers
{
    protected const int DEFAULT_RCON_PORT = 27020;

    public function metadata(): array
    {
        $metadata = parent::metadata();
        $metadata['help'] = 'arkascended::messages.rcon_help';
        unset($metadata['fields']['query_port']);

        return $metadata;
    }

    public function status(Server $server): array
    {
        $started = microtime(true);
        $players = $this->onlinePlayers($server);

        return [
            'players_online' => count($players),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'version' => '',
            'transport' => $server->connection,
        ];
    }

    public function onlinePlayers(Server $server): array
    {
        $body = trim($this->exchange($server, 'ListPlayers', static function (): void {
        }, true));
        $identifiers = [];
        if (!preg_match('/^No Players Connected\.?$/iD', $body)) {
            $rows = preg_split('/\r\n|\r|\n/', $body);
            $first = null;
            foreach ($rows as $index => $row) {
                if (!preg_match('/^([0-9]{1,5})\. +[^\x00-\x1f\x7f]+, *([a-f0-9]{32})$/iD', $row, $parts)) {
                    throw new TransportFailure('transports.invalid_response');
                }
                $first ??= (int) $parts[1];
                $identifier = strtolower($parts[2]);
                if (
                    !in_array($first, [0, 1], true) ||
                    (int) $parts[1] !== $first + $index ||
                    trim($identifier, '0') === '' ||
                    isset($identifiers[$identifier])
                ) {
                    throw new TransportFailure('transports.invalid_response');
                }
                $identifiers[$identifier] = true;
            }
        }

        return array_keys($identifiers);
    }
}
