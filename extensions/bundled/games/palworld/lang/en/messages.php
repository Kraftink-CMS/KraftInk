<?php

return [
    'description' => 'Palworld profiles and dedicated server administration through its official REST API.',
    'identity' => 'Platform user ID, for example steam_76561198000000001',
    'manual' => 'No connection',
    'rest' => 'REST API',
    'rest_help' => 'Enable RESTAPIEnabled on the server. Enter the port set in RESTAPIPort and the administrator password. Available commands are announce, kick, ban, unban and save. Inventory rewards require an additional integration.',
    'api_port' => 'REST API port',
    'api_encryption' => 'API encryption',
    'admin_password' => 'Administrator password',
    'invalid_identity' => 'Enter the platform user ID shown by the Palworld server, with its prefix. A character name or SteamID alone is insufficient.',
    'unsupported_command' => 'This command is not supported by the native Palworld REST API. Use announce, kick, ban, unban or save.',
];
