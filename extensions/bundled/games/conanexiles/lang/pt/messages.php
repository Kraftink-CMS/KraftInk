<?php

return [
    'description' => 'Identidades de plataforma declaradas, consultas do servidor e administração RCON. O identificador de plataforma não verifica uma conta Funcom. As recompensas exigem comandos ou mods que aceitem esse identificador.',
    'identity' => 'Identificador de plataforma (SteamID64 ou EOS)',
    'invalid_identity' => 'Introduza um SteamID64 individual válido ou um identificador EOS não nulo com 32 carateres hexadecimais.',
    'manual' => 'Sem ligação',
    'query' => 'Source Query',
    'rcon' => 'Source RCON',
    'query_help' => 'Introduza a porta de consulta definida por GameServerQueryPort ou QueryPort. A consulta publica apenas o número de jogadores, sem nomes.',
    'query_port_help' => 'Deixa este campo em branco para usar a porta 27015.',
    'rcon_help' => 'Ative RconEnabled e defina RconPort e RconPassword na secção RconPlugin de Game.ini. A porta TCP predefinida é 25575. Use comandos suportados pelo Conan Exiles ou pelos seus mods. Um identificador de plataforma não é um nome de personagem nem um índice temporário.',
];
