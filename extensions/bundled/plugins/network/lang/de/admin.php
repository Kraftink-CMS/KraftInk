<?php

return [
    'title' => 'Netzwerk und Cloudflare',
    'description' => 'Erkennung von Besucheradressen und HTTPS hinter Cloudflare oder einem eigenen Proxy.',
    'help' => 'Wählen Sie den Proxy Ihres Hostinganbieters. Besucher- und HTTPS-Informationen werden nur von vertrauenswürdigen Proxys akzeptiert. Wenn Sie Network deaktivieren, verwendet die Website die direkte Verbindung.',
    'current_ip' => 'Ihre erkannte Adresse: :ip',
    'mode' => 'Verbindungsmodus',
    'modes' => [
        'direct' => 'Direkte Verbindung',
        'cloudflare' => 'Cloudflare',
        'custom' => 'Eigener Proxy',
    ],
    'proxies' => 'Adressen vertrauenswürdiger Proxys',
    'proxies_help' => 'Eine IP-Adresse oder ein CIDR-Netz pro Zeile, bereitgestellt von Ihrem Hostinganbieter. Platzhalter und Netze, die das gesamte Internet abdecken, werden abgelehnt.',
    'cloudflare_help' => 'Die Cloudflare-Netze werden mit Network geliefert und über CMS-Updates gepflegt. Im Cloudflare-Modus bleibt die Verbindungsadresse erhalten, wenn die Anfrage nicht von Cloudflare stammt.',
    'save' => 'Speichern',
    'saved' => 'Die Netzwerkeinstellungen wurden gespeichert.',
    'invalid_proxy' => 'Eine Proxyadresse ist ungültig.',
    'audit' => [
        'configured' => 'Netzwerk und vertrauenswürdige Adressen konfiguriert',
        'cloudflare_refreshed' => 'Cloudflare-Netze aktualisiert',
    ],
];
