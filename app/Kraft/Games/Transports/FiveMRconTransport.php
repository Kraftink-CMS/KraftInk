<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use App\Models\Servers\Server;
use Closure;
use Illuminate\Http\Client\ConnectionException;

class FiveMRconTransport implements Transport
{
    public function __construct(private SocketTarget $target, private FiveMStatusTransport $query)
    {
    }

    public function metadata(): array
    {
        return array_replace($this->query->metadata(), [
            'commands' => true,
            'secret' => true,
            'help' => 'admin.servers.transport.udp_rcon_help',
        ]);
    }

    /**
     * @throws ConnectionException
     */
    public function status(Server $server): array
    {
        return $this->query->status($server);
    }

    public function execute(Server $server, string $command, Closure $writing): void
    {
        if (
            !$server->secret ||
            strlen($server->secret) > 512 ||
            preg_match('/[\x00-\x20\x7f]/', $server->secret) ||
            strlen($command) > 2048 ||
            preg_match('/[\x00\r\n]/', $command)
        ) {
            throw new TransportFailure('transports.authentication_failed', true);
        }
        $socket = $this->target->datagram($server->host, $server->port);
        try {
            $writing();
            $socket->send("\xff\xff\xff\xffrcon ".$server->secret.' '.$command."\x00");
            $reply = $socket->receive();
            if (
                !str_starts_with($reply, "\xff\xff\xff\xffprint\n") &&
                !str_starts_with($reply, "\xff\xff\xff\xffprint ")
            ) {
                throw new TransportFailure('transports.invalid_response');
            }
            if (preg_match('/(?:invalid|bad|incorrect|no) rcon password|rcon.*disabled/i', substr($reply, 10))) {
                throw new TransportFailure('transports.authentication_failed', true);
            }
        } finally {
            $socket->close();
        }
    }
}
