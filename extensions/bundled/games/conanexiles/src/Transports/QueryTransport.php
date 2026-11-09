<?php

namespace KraftInk\Games\ConanExiles\Transports;

use App\Kraft\Games\Transports\SourceQueryTransport;

class QueryTransport extends SourceQueryTransport
{
    protected const int DEFAULT_QUERY_PORT = 27015;

    public function metadata(): array
    {
        return array_replace_recursive(parent::metadata(), [
            'fields' => [
                'query_port' => [
                    'help' => 'conanexiles::messages.query_port_help',
                ],
            ],
            'help' => 'conanexiles::messages.query_help',
        ]);
    }
}
