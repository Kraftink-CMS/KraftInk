<?php

return [
    'description' => 'Angegebene Plattformidentitäten, Serverabfragen und RCON-Verwaltung. Die Plattformkennung bestätigt kein Funcom-Konto. Spielerbelohnungen benötigen Befehle oder Mods, die diese Kennung unterstützen.',
    'identity' => 'Plattformkennung (SteamID64 oder EOS)',
    'invalid_identity' => 'Gib eine gültige individuelle SteamID64 oder eine EOS-Kennung mit 32 Hexadezimalzeichen ein, die nicht nur aus Nullen besteht.',
    'manual' => 'Ohne Verbindung',
    'query' => 'Source Query',
    'rcon' => 'Source RCON',
    'query_help' => 'Gib den mit GameServerQueryPort oder QueryPort eingerichteten Abfrageport an. Die Abfrage veröffentlicht nur Spielerzahlen ohne Namen.',
    'query_port_help' => 'Lass das Feld leer, um Port 27015 zu verwenden.',
    'rcon_help' => 'Aktiviere RconEnabled und lege RconPort und RconPassword im Abschnitt RconPlugin der Game.ini fest. Der TCP-Standardport ist 25575. Verwende Befehle, die Conan Exiles oder deine Mods unterstützen. Eine Plattformkennung ist weder Charaktername noch vorübergehender Spielerindex.',
];
