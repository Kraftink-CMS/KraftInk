<?php

return [
    'title' => 'Réseau et Cloudflare',
    'description' => 'Détection des adresses des visiteurs et de HTTPS derrière Cloudflare ou un proxy personnalisé.',
    'help' => 'Choisissez le relais utilisé par votre hébergement. Les informations sur le visiteur et HTTPS sont acceptées uniquement depuis un proxy déclaré fiable. Si vous désactivez Network, le site utilise la connexion directe.',
    'current_ip' => 'Votre adresse détectée : :ip',
    'mode' => 'Mode de connexion',
    'modes' => [
        'direct' => 'Connexion directe',
        'cloudflare' => 'Cloudflare',
        'custom' => 'Proxy personnalisé',
    ],
    'proxies' => 'Adresses des proxies fiables',
    'proxies_help' => 'Une adresse IP ou un réseau CIDR par ligne, fournis par votre hébergeur. Les jokers et les réseaux couvrant tout Internet sont refusés.',
    'cloudflare_help' => 'Les plages Cloudflare sont fournies avec Network et suivent les mises à jour du CMS. Le mode Cloudflare conserve l’adresse de connexion lorsque la requête ne provient pas de Cloudflare.',
    'save' => 'Enregistrer',
    'saved' => 'Les réglages réseau ont été enregistrés.',
    'invalid_proxy' => 'Une adresse de proxy est invalide.',
    'audit' => [
        'configured' => 'Réseau et adresses de confiance configurés',
        'cloudflare_refreshed' => 'Réseaux Cloudflare actualisés',
    ],
];
