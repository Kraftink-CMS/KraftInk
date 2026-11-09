<?php

return [
    'steam_id' => 'SteamID64',
    'manual' => 'Sem ligação',
    'query' => 'Source Query',
    'http' => 'HTTP',
    'source_rcon' => 'Source RCON',
    'webrcon' => 'WebRCON',
    'fivem_rcon' => 'FXServer RCON',
    'battleye' => 'BattlEye RCON',
    'telnet' => 'Telnet',
    'authentication' => 'Acesso às contas do site',
    'open' => 'Conta padrão',
    'open_help' => 'Os membros utilizam os métodos de início de sessão do site. As identidades declaradas pelo utilizador permanecem por verificar.',
    'verified' => 'Conta Steam verificada',
    'verified_help' => 'Associa a Steam para comprovar a tua identidade. Esta ligação não verifica a posse do jogo.',
    'identity_required' => 'Associa a tua conta Steam para aceder a este site.',
    'setup_required' => 'Ativa a Steam e verifica a tua própria identidade para este jogo antes de exigir esta proteção.',
    'provider_required' => 'Mantém uma ligação Steam ativa ou desativa primeiro a exigência de uma conta Steam verificada.',
    'invalid_id' => 'Introduz um identificador individual válido SteamID64, SteamID2, SteamID3 ou steam: hexadecimal.',
    'api_key' => 'Chave opcional da API Web da Steam',
    'avatar' => 'Avatar do perfil Steam através de uma chave da API Web configurada. Os avatares enviados para o site têm prioridade.',
    'profile_unavailable' => 'O perfil Steam está indisponível. É necessária uma chave da API Web da Steam para consultar perfis públicos.',
    'oauth' => [
        'description' => 'A Steam utiliza OpenID: não é necessário um identificador de aplicação. Ativa este serviço para permitir o início de sessão e a associação de contas Steam.',
        'note' => 'A chave opcional da API Web permite obter nomes e avatares de perfis públicos. A Steam não partilha o endereço de e-mail: os membros introduzem-no separadamente.',
    ],
];
