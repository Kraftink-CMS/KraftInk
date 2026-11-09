<?php

return [
    'title' => 'Rede e Cloudflare',
    'description' => 'Deteção dos endereços dos visitantes e de HTTPS por trás da Cloudflare ou de um proxy personalizado.',
    'help' => 'Escolha o proxy utilizado pelo seu alojamento. As informações do visitante e de HTTPS só são aceites de um proxy de confiança. Ao desativar o Network, o site utiliza a ligação direta.',
    'current_ip' => 'O seu endereço detetado: :ip',
    'mode' => 'Modo de ligação',
    'modes' => [
        'direct' => 'Ligação direta',
        'cloudflare' => 'Cloudflare',
        'custom' => 'Proxy personalizado',
    ],
    'proxies' => 'Endereços dos proxies de confiança',
    'proxies_help' => 'Um endereço IP ou uma rede CIDR por linha, fornecidos pelo seu alojamento. Os caracteres universais e as redes que abrangem toda a Internet são recusados.',
    'cloudflare_help' => 'As redes da Cloudflare são incluídas no Network e mantidas pelas atualizações do CMS. O modo Cloudflare conserva o endereço da ligação quando o pedido não vem da Cloudflare.',
    'save' => 'Guardar',
    'saved' => 'As definições de rede foram guardadas.',
    'invalid_proxy' => 'Um endereço de proxy é inválido.',
    'audit' => [
        'configured' => 'Rede e endereços de confiança configurados',
        'cloudflare_refreshed' => 'Redes da Cloudflare atualizadas',
    ],
];
