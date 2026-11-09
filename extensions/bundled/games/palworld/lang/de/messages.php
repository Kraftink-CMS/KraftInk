<?php

return [
    'description' => 'Palworld-Profile und Verwaltung dedizierter Server über ihre offizielle REST-API.',
    'identity' => 'Plattformkennung, zum Beispiel steam_76561198000000001',
    'manual' => 'Ohne Verbindung',
    'rest' => 'REST-API',
    'rest_help' => 'Aktiviere RESTAPIEnabled auf dem Server. Gib den unter RESTAPIPort eingestellten Port und das Administratorpasswort ein. Verfügbare Befehle sind announce, kick, ban, unban und save. Inventarbelohnungen benötigen eine zusätzliche Integration.',
    'api_port' => 'REST-API-Port',
    'api_encryption' => 'API-Verschlüsselung',
    'admin_password' => 'Administratorpasswort',
    'invalid_identity' => 'Gib die vom Palworld-Server angezeigte Plattformkennung mit Präfix ein. Ein Charaktername oder eine SteamID allein reicht nicht aus.',
    'unsupported_command' => 'Dieser Befehl wird von der nativen Palworld-REST-API nicht unterstützt. Verwende announce, kick, ban, unban oder save.',
];
