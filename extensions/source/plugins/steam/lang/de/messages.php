<?php

return [
    'steam_id' => 'SteamID64',
    'manual' => 'Ohne Verbindung',
    'query' => 'Source Query',
    'http' => 'HTTP',
    'source_rcon' => 'Source RCON',
    'webrcon' => 'WebRCON',
    'fivem_rcon' => 'FXServer RCON',
    'battleye' => 'BattlEye RCON',
    'telnet' => 'Telnet',
    'authentication' => 'Zugang zu Website-Konten',
    'open' => 'Standardkonto',
    'open_help' => 'Mitglieder verwenden die Anmeldeverfahren der Website. Selbst angegebene Identitäten bleiben unbestätigt.',
    'verified' => 'Bestätigtes Steam-Konto',
    'verified_help' => 'Verknüpfe Steam, um deine Identität nachzuweisen. Diese Verbindung bestätigt nicht den Besitz des Spiels.',
    'identity_required' => 'Verknüpfe dein Steam-Konto, um auf diese Website zuzugreifen.',
    'setup_required' => 'Aktiviere Steam und bestätige deine eigene Identität für dieses Spiel, bevor du diesen Schutz verlangst.',
    'provider_required' => 'Lasse eine Steam-Anmeldung aktiviert oder deaktiviere zuerst die Pflicht zu einem bestätigten Steam-Konto.',
    'invalid_id' => 'Gib eine gültige persönliche SteamID64, SteamID2, SteamID3 oder eine hexadezimale steam:-Kennung ein.',
    'api_key' => 'Optionaler Steam-Web-API-Schlüssel',
    'avatar' => 'Steam-Profilbild über einen eingerichteten Web-API-Schlüssel. Lokal hochgeladene Profilbilder haben Vorrang.',
    'profile_unavailable' => 'Das Steam-Profil ist nicht verfügbar. Zum Abrufen öffentlicher Profile ist ein Steam-Web-API-Schlüssel erforderlich.',
    'oauth' => [
        'description' => 'Steam verwendet OpenID: Eine Anwendungs-ID ist nicht erforderlich. Aktiviere diesen Dienst, um die Anmeldung und Kontoverknüpfung mit Steam zu erlauben.',
        'note' => 'Der optionale Web-API-Schlüssel ruft öffentliche Profilnamen und Profilbilder ab. Steam übermittelt keine E-Mail-Adresse: Mitglieder geben sie separat an.',
    ],
];
