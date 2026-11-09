<?php

return [
    'description' => 'Identidades EOS declaradas e comandos RCON autenticados. ListPlayers fornece o número de jogadores e a presença EOS para entregas pendentes sem publicar nomes ou identificadores. O limite de jogadores não é fornecido.',
    'identity' => 'Identificador EOS do ARK',
    'invalid_identity' => 'Introduza um identificador EOS não nulo com 32 carateres hexadecimais.',
    'manual' => 'Sem ligação',
    'rcon' => 'Source RCON',
    'rcon_help' => 'Ative RCONEnabled e defina RCONPort e ServerAdminPassword em GameUserSettings.ini. A porta TCP predefinida é 27020. O estado usa o comando autenticado ListPlayers. Os destinatários usam o identificador EOS, não um SteamID.',
];
