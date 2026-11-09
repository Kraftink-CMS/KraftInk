<?php

return [
    'create' => 'API-Zugang erstellen',
    'create_help' => 'Wähle die Daten, auf die diese Anwendung zugreifen darf. Ein API-Zugang gewährt nie mehr Berechtigungen als dein Konto.',
    'unavailable' => 'Zum Erstellen eines Zugangs muss die API aktiviert sein und dein Konto die lokale Anmeldung erlauben. Bestehende Zugänge kannst du weiterhin widerrufen.',
    'name' => 'Name der Anwendung',
    'lifetime' => 'Gültigkeitsdauer in Minuten',
    'lifetime_help' => 'Die Website-Einstellung begrenzt die Gültigkeitsdauer auf höchstens 30 Tage.',
    'rights' => 'Erlaubte Daten und Aktionen',
    'rights_help' => 'Die Kontoberechtigungen und die Verfügbarkeit der Erweiterungen werden bei jeder Anfrage geprüft.',
    'password' => 'Aktuelles Passwort',
    'code' => 'Code der Zwei-Faktor-Authentifizierung',
    'created' => 'Dein API-Zugang ist bereit',
    'copy_once' => 'Kopiere dieses Geheimnis jetzt und speichere es in der vorgesehenen Anwendung. Es wird nicht erneut angezeigt. Wer es besitzt, kann die ausgewählten Berechtigungen nutzen.',
    'secret' => 'Bearer-Token',
    'back' => 'Zurück zu meinen API-Zugängen',
    'scopes' => [
        'site_news' => ['label' => 'Öffentliche Neuigkeiten lesen', 'description' => 'Veröffentlichte Neuigkeiten und ihre öffentlichen Inhalte lesen. Entwürfe und geschützte Inhalte bleiben ausgeschlossen.'],
        'site_servers' => ['label' => 'Öffentlichen Serverstatus lesen', 'description' => 'Verfügbarkeit und Spielerzahlen öffentlicher Server abrufen. Kein Zugriff auf interne Adressen, Geheimnisse oder Befehle.'],
        'profile' => [
            'label' => 'Mein Profil lesen',
            'description' => 'Kennung, Benutzername, Ränge und Erstellungsdatum deines Kontos.',
        ],
        'games' => [
            'label' => 'Meine Spielidentitäten lesen',
            'description' => 'Kennungen, Benutzernamen und Verifizierungsstatus deiner Spielprofile.',
        ],
        'email' => [
            'label' => 'Meine E-Mail-Adresse lesen',
            'description' => 'Die mit deinem Konto verknüpfte E-Mail-Adresse.',
        ],
    ],
];
