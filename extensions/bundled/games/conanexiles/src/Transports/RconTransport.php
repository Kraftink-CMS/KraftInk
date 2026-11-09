<?php

namespace KraftInk\Games\ConanExiles\Transports;

use App\Kraft\Games\Transports\SocketTarget;
use App\Kraft\Games\Transports\SourceRconTransport;

class RconTransport extends SourceRconTransport
{
    protected const int DEFAULT_RCON_PORT = 25575;

    public function __construct(SocketTarget $target, QueryTransport $query)
    {
        parent::__construct($target, $query);
    }

    public function metadata(): array
    {
        return array_replace(parent::metadata(), ['help' => 'conanexiles::messages.rcon_help']);
    }
}
