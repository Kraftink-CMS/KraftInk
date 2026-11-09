<?php

return [
    'description' => 'Profils Palworld et administration des serveurs dédiés avec leur API REST officielle.',
    'identity' => 'Identifiant de plateforme, par exemple steam_76561198000000001',
    'manual' => 'Sans liaison',
    'rest' => 'API REST',
    'rest_help' => 'Activez RESTAPIEnabled sur le serveur. Renseignez le port RESTAPIPort et le mot de passe administrateur. L’API permet d’utiliser announce, kick, ban, unban et save. Les récompenses d’inventaire exigent une intégration supplémentaire.',
    'api_port' => 'Port de l’API REST',
    'api_encryption' => 'Chiffrement de l’API',
    'admin_password' => 'Mot de passe administrateur',
    'invalid_identity' => 'Indiquez l’identifiant de plateforme affiché par le serveur Palworld, avec son préfixe. Un nom de personnage ou un SteamID seul ne suffit pas.',
    'unsupported_command' => 'Cette commande n’est pas disponible dans l’API REST native de Palworld. Utilisez announce, kick, ban, unban ou save.',
];
