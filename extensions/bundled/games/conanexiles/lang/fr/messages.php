<?php

return [
    'description' => 'Identités de plateforme déclarées, requêtes serveur et administration RCON. L’identifiant de plateforme ne vérifie pas un compte Funcom. Les récompenses nécessitent des commandes ou mods acceptant cet identifiant.',
    'identity' => 'Identifiant de plateforme (SteamID64 ou EOS)',
    'invalid_identity' => 'Indiquez un SteamID64 individuel valide ou un identifiant EOS non nul de 32 caractères hexadécimaux.',
    'manual' => 'Sans liaison',
    'query' => 'Source Query',
    'rcon' => 'Source RCON',
    'query_help' => 'Indiquez le port de requête défini par GameServerQueryPort ou QueryPort. La requête publie uniquement les effectifs, sans noms de joueurs.',
    'query_port_help' => 'Laissez vide pour utiliser le port 27015.',
    'rcon_help' => 'Activez RconEnabled et définissez RconPort et RconPassword dans la section RconPlugin de Game.ini. Le port TCP par défaut est 25575. Utilisez des commandes prises en charge par Conan Exiles ou vos mods. Un identifiant de plateforme n’est ni un nom de personnage ni un index temporaire.',
];
