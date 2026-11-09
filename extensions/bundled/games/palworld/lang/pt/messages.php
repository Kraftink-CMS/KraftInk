<?php

return [
    'description' => 'Perfis Palworld e administração de servidores dedicados através da API REST oficial.',
    'identity' => 'Identificador da plataforma, por exemplo steam_76561198000000001',
    'manual' => 'Sem ligação',
    'rest' => 'API REST',
    'rest_help' => 'Ativa RESTAPIEnabled no servidor. Indica a porta definida em RESTAPIPort e a palavra-passe de administrador. Os comandos disponíveis são announce, kick, ban, unban e save. As recompensas de inventário exigem uma integração adicional.',
    'api_port' => 'Porta da API REST',
    'api_encryption' => 'Encriptação da API',
    'admin_password' => 'Palavra-passe de administrador',
    'invalid_identity' => 'Introduza o identificador da plataforma apresentado pelo servidor Palworld, com o prefixo. Um nome de personagem ou apenas um SteamID não é suficiente.',
    'unsupported_command' => 'Este comando não está disponível na API REST nativa do Palworld. Use announce, kick, ban, unban ou save.',
];
