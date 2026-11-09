<?php

return [
    'title' => 'Red y Cloudflare',
    'description' => 'Detección de las direcciones de los visitantes y de HTTPS detrás de Cloudflare o de un proxy personalizado.',
    'help' => 'Elija el proxy utilizado por su alojamiento. La información del visitante y de HTTPS solo se acepta desde un proxy de confianza. Al desactivar Network, el sitio utiliza la conexión directa.',
    'current_ip' => 'Su dirección detectada: :ip',
    'mode' => 'Modo de conexión',
    'modes' => [
        'direct' => 'Conexión directa',
        'cloudflare' => 'Cloudflare',
        'custom' => 'Proxy personalizado',
    ],
    'proxies' => 'Direcciones de los proxies de confianza',
    'proxies_help' => 'Una dirección IP o una red CIDR por línea, facilitadas por su alojamiento. Se rechazan los comodines y las redes que abarcan todo Internet.',
    'cloudflare_help' => 'Las redes de Cloudflare se incluyen con Network y se mantienen mediante las actualizaciones del CMS. El modo Cloudflare conserva la dirección de conexión cuando la solicitud no procede de Cloudflare.',
    'save' => 'Guardar',
    'saved' => 'Se han guardado los ajustes de red.',
    'invalid_proxy' => 'Una dirección de proxy no es válida.',
    'audit' => [
        'configured' => 'Red y direcciones de confianza configuradas',
        'cloudflare_refreshed' => 'Redes de Cloudflare actualizadas',
    ],
];
