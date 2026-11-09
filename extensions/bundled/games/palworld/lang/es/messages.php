<?php

return [
    'description' => 'Perfiles de Palworld y administración de servidores dedicados mediante su API REST oficial.',
    'identity' => 'Identificador de plataforma, por ejemplo steam_76561198000000001',
    'manual' => 'Sin conexión',
    'rest' => 'API REST',
    'rest_help' => 'Activa RESTAPIEnabled en el servidor. Indica el puerto definido en RESTAPIPort y la contraseña de administrador. Los comandos disponibles son announce, kick, ban, unban y save. Las recompensas de inventario requieren una integración adicional.',
    'api_port' => 'Puerto de la API REST',
    'api_encryption' => 'Cifrado de la API',
    'admin_password' => 'Contraseña de administrador',
    'invalid_identity' => 'Introduce el identificador de plataforma mostrado por el servidor Palworld, con su prefijo. Un nombre de personaje o un SteamID solo no basta.',
    'unsupported_command' => 'Esta orden no está disponible en la API REST nativa de Palworld. Usa announce, kick, ban, unban o save.',
];
