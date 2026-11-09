<?php

use App\Kraft\Migration\Adapters\Azuriom\Adapter as AzuriomAdapter;
use App\Kraft\Migration\Adapters\KraftInk\Adapter;
use App\Kraft\Migration\Adapters\MineWeb\Adapter as MineWebAdapter;

return [
    'directory' => storage_path('app/private/imports'),
    'chunk_size' => 250,
    'locale' => 'fr',
    'currency' => 'EUR',
    'adapters' => [
        'kraftink' => Adapter::class,
        'azuriom' => AzuriomAdapter::class,
        'mineweb' => MineWebAdapter::class,
    ],
];
