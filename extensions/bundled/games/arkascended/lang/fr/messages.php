<?php

return [
    'description' => 'Identités EOS déclarées et commandes RCON authentifiées. ListPlayers fournit le nombre de joueurs et la présence EOS pour les livraisons en attente, sans publier les noms ou identifiants. Le maximum de joueurs n’est pas fourni.',
    'identity' => 'Identifiant EOS ARK',
    'invalid_identity' => 'Indiquez un identifiant EOS non nul de 32 caractères hexadécimaux.',
    'manual' => 'Sans liaison',
    'rcon' => 'Source RCON',
    'rcon_help' => 'Activez RCONEnabled, puis définissez RCONPort et ServerAdminPassword dans GameUserSettings.ini. Le port TCP par défaut est 27020. L’état utilise la commande authentifiée ListPlayers. Les cibles utilisent l’identifiant EOS, pas un SteamID.',
];
