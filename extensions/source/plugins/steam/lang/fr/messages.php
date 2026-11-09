<?php

return [
    'steam_id' => 'SteamID64',
    'manual' => 'Sans liaison',
    'query' => 'Source Query',
    'http' => 'HTTP',
    'source_rcon' => 'Source RCON',
    'webrcon' => 'WebRCON',
    'fivem_rcon' => 'FXServer RCON',
    'battleye' => 'BattlEye RCON',
    'telnet' => 'Telnet',
    'authentication' => 'Accès aux comptes du site',
    'open' => 'Compte classique',
    'open_help' => 'Les membres utilisent les moyens de connexion du site. Les identités déclarées restent non vérifiées.',
    'verified' => 'Compte Steam vérifié',
    'verified_help' => 'Associez Steam pour confirmer votre identité. Cette connexion ne vérifie pas la possession du jeu.',
    'identity_required' => 'Associez votre compte Steam pour accéder à ce site.',
    'setup_required' => 'Activez Steam et vérifiez votre propre identité pour ce jeu avant d’exiger cette protection.',
    'provider_required' => 'Conservez une connexion Steam active ou désactivez d’abord l’obligation de compte Steam vérifié.',
    'invalid_id' => 'Renseignez un SteamID64 individuel valide, un SteamID2, un SteamID3 ou un identifiant steam: hexadécimal.',
    'api_key' => 'Clé Web API Steam facultative',
    'avatar' => 'Avatar du profil Steam, avec une clé Web API configurée. Les avatars importés localement restent prioritaires.',
    'profile_unavailable' => 'Le profil Steam est indisponible. Une clé Web API Steam est nécessaire pour lire les profils publics.',
    'oauth' => [
        'description' => 'Steam utilise OpenID : aucun identifiant d’application n’est nécessaire. Activez le service pour permettre la connexion ou l’association d’un compte.',
        'note' => 'La clé Web API facultative permet de lire le pseudo public et l’avatar. Steam ne transmet aucune adresse e-mail : le membre la renseigne séparément.',
    ],
];
