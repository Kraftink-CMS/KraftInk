<?php

return [
    'description' => 'Identidades de plataforma declaradas, consultas del servidor y administración RCON. El identificador de plataforma no verifica una cuenta Funcom. Las recompensas requieren comandos o mods que acepten ese identificador.',
    'identity' => 'Identificador de plataforma (SteamID64 o EOS)',
    'invalid_identity' => 'Introduce un SteamID64 individual válido o un identificador EOS no nulo de 32 caracteres hexadecimales.',
    'manual' => 'Sin conexión',
    'query' => 'Source Query',
    'rcon' => 'Source RCON',
    'query_help' => 'Introduce el puerto de consulta definido con GameServerQueryPort o QueryPort. La consulta solo publica cantidades, sin nombres de jugadores.',
    'query_port_help' => 'Deja este campo vacío para usar el puerto 27015.',
    'rcon_help' => 'Activa RconEnabled y define RconPort y RconPassword en la sección RconPlugin de Game.ini. El puerto TCP predeterminado es 25575. Usa comandos compatibles con Conan Exiles o tus mods. Un identificador de plataforma no es un nombre de personaje ni un índice temporal.',
];
