<?php

return [
    'id' => 'kraftink-site',
    'url' => 'https://kraftink.com/api/market/announcements',
    'signing_key' => 'kraftink-site',
    'enabled' => env('KRAFTINK_SITE_ANNOUNCEMENTS_ENABLED', true),
];
