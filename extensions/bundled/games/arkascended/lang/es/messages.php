<?php

return [
    'description' => 'Identidades EOS declaradas y comandos RCON autenticados. ListPlayers proporciona el número de jugadores y la presencia EOS para las entregas pendientes sin publicar nombres ni identificadores. No se proporciona el máximo de jugadores.',
    'identity' => 'Identificador EOS de ARK',
    'invalid_identity' => 'Introduce un identificador EOS no nulo de 32 caracteres hexadecimales.',
    'manual' => 'Sin conexión',
    'rcon' => 'Source RCON',
    'rcon_help' => 'Activa RCONEnabled y define RCONPort y ServerAdminPassword en GameUserSettings.ini. El puerto TCP predeterminado es 27020. El estado usa el comando autenticado ListPlayers. Los destinatarios usan el identificador EOS, no un SteamID.',
];
