<?php

return [
    'title' => 'Datenaufbewahrung',
    'description' => 'Wähle, wie lange ältere Versionen deiner Inhalte aufbewahrt werden.',
    'saved' => 'Die Bereinigungseinstellungen wurden gespeichert.',
    'command_description' => 'Alte Versionen und temporäre Daten gemäß der konfigurierten Aufbewahrungsrichtlinie bereinigen',
    'schedule_help' => 'Diese Zeiträume gelten ab der nächsten Bereinigung. Mit aktivierten automatischen Aufgaben läuft die Wartung stündlich. Eine manuelle Bereinigung ist ebenfalls möglich.',
    'history_title' => 'Inhaltsverlauf',
    'other_cleanups' => 'Weitere Bereinigungen',
    'fields' => [
        'history' => [
            'label' => 'Dauer des Verlaufs in Tagen',
            'help' => 'Ältere Versionen werden automatisch gelöscht.',
        ],
        'temporary' => [
            'label' => 'Ungenutzte Dateien in Tagen',
            'help' => 'Aus deinen Inhalten und deren Verlauf entfernte Bilder sowie abgelaufene Gastzugänge.',
        ],
        'technical' => [
            'label' => 'Alte Zustellungen und Aufgaben in Tagen',
            'help' => 'Alte Benachrichtigungszustellungen und abgeschlossene Aufgaben. Aufgaben, die erneut ausgeführt werden müssen, bleiben erhalten.',
        ],
    ],
    'history_limit' => 'Anzahl aufzubewahrender Versionen pro Inhalt',
    'history_limit_help' => 'Wenn diese Grenze überschritten wird, werden die ältesten Versionen gelöscht.',
    'preserved_help' => 'Deine Entwürfe, aktuellen Inhalte und weiterhin verwendeten Bilder bleiben erhalten.',
    'audit_link' => 'Aufbewahrung des Aktivitätsprotokolls festlegen',
];
