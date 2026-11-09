<?php

return [
    'sources' => [
        [
            'id' => 'kraftink',
            'name' => 'KraftInk',
            'url' => env('KRAFTINK_MARKET_URL') ?: 'https://kraftink.com/api/market/catalogue',
            'supports_site_key' => true,
            'revocations_url' => 'https://kraftink.com/api/market/revocations',
            'signing_key' => 'kraftink-site',
        ],
    ],
    'cache_seconds' => 60,
    'stale_seconds' => 86400,
    'schema_version' => 1,
];
