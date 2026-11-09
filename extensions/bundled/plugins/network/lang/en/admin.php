<?php

return [
    'title' => 'Network and Cloudflare',
    'description' => 'Visitor IP and HTTPS detection behind Cloudflare or a custom proxy.',
    'help' => 'Choose the proxy used by your hosting provider. Visitor and HTTPS information is accepted only from a trusted proxy. Disabling Network makes the site use the direct connection.',
    'current_ip' => 'Your detected address: :ip',
    'mode' => 'Connection mode',
    'modes' => [
        'direct' => 'Direct connection',
        'cloudflare' => 'Cloudflare',
        'custom' => 'Custom proxy',
    ],
    'proxies' => 'Trusted proxy addresses',
    'proxies_help' => 'One IP address or CIDR range per line, supplied by your hosting provider. Wildcards and ranges covering the whole Internet are rejected.',
    'cloudflare_help' => 'Cloudflare ranges are included with Network and maintained through CMS updates. Cloudflare mode keeps the connection address when a request does not come from Cloudflare.',
    'save' => 'Save',
    'saved' => 'Network settings have been saved.',
    'invalid_proxy' => 'A proxy address is invalid.',
    'audit' => [
        'configured' => 'Network and trusted addresses configured',
        'cloudflare_refreshed' => 'Cloudflare networks refreshed',
    ],
];
