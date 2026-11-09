<?php

return [
    'description' => 'Angegebene EOS-Identitäten und authentifizierte RCON-Befehle. ListPlayers liefert die Spielerzahl und EOS-Anwesenheit für ausstehende Lieferungen, ohne Namen oder Kennungen zu veröffentlichen. Die maximale Spielerzahl wird nicht geliefert.',
    'identity' => 'ARK-EOS-Kennung',
    'invalid_identity' => 'Gib eine EOS-Kennung mit 32 Hexadezimalzeichen ein, die nicht nur aus Nullen besteht.',
    'manual' => 'Ohne Verbindung',
    'rcon' => 'Source RCON',
    'rcon_help' => 'Aktiviere RCONEnabled und lege RCONPort und ServerAdminPassword in GameUserSettings.ini fest. Der TCP-Standardport ist 27020. Der Status verwendet den authentifizierten Befehl ListPlayers. Ziele werden über die EOS-Kennung statt einer SteamID angegeben.',
];
