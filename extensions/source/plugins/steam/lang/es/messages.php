<?php

return [
    'steam_id' => 'SteamID64',
    'manual' => 'Sin conexión',
    'query' => 'Source Query',
    'http' => 'HTTP',
    'source_rcon' => 'Source RCON',
    'webrcon' => 'WebRCON',
    'fivem_rcon' => 'FXServer RCON',
    'battleye' => 'BattlEye RCON',
    'telnet' => 'Telnet',
    'authentication' => 'Acceso a las cuentas del sitio',
    'open' => 'Cuenta estándar',
    'open_help' => 'Los miembros utilizan los métodos de acceso del sitio. Las identidades declaradas por el usuario permanecen sin verificar.',
    'verified' => 'Cuenta de Steam verificada',
    'verified_help' => 'Vincula Steam para demostrar tu identidad. Esta conexión no verifica la propiedad del juego.',
    'identity_required' => 'Vincula tu cuenta de Steam para acceder a este sitio.',
    'setup_required' => 'Activa Steam y verifica tu propia identidad para este juego antes de exigir esta protección.',
    'provider_required' => 'Mantén una conexión de Steam activa o desactiva primero el requisito de cuenta de Steam verificada.',
    'invalid_id' => 'Introduce un identificador individual válido SteamID64, SteamID2, SteamID3 o steam: hexadecimal.',
    'api_key' => 'Clave opcional de la API web de Steam',
    'avatar' => 'Avatar del perfil de Steam mediante una clave de API web configurada. Los avatares subidos al sitio tienen prioridad.',
    'profile_unavailable' => 'El perfil de Steam no está disponible. Se necesita una clave de API web de Steam para consultar perfiles públicos.',
    'oauth' => [
        'description' => 'Steam utiliza OpenID: no se necesita un identificador de aplicación. Activa este servicio para permitir el acceso y la vinculación de cuentas de Steam.',
        'note' => 'La clave opcional de API web permite obtener nombres y avatares de perfiles públicos. Steam no comparte la dirección de correo: los miembros la introducen por separado.',
    ],
];
