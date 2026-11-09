<?php

return [
    'description' => 'Declared platform identities, server queries and RCON administration. The platform ID is not a verified Funcom account. Player rewards require commands or mods supporting that identifier.',
    'identity' => 'Platform identifier (SteamID64 or EOS)',
    'invalid_identity' => 'Enter a valid individual SteamID64 or a nonzero EOS identifier containing 32 hexadecimal characters.',
    'manual' => 'No connection',
    'query' => 'Source Query',
    'rcon' => 'Source RCON',
    'query_help' => 'Set the server query port configured with GameServerQueryPort or QueryPort. The query publishes counts only, without player names.',
    'query_port_help' => 'Leave blank to use port 27015.',
    'rcon_help' => 'Enable RconEnabled and set RconPort and RconPassword in Game.ini under RconPlugin. The default TCP port is 25575. Use commands supported by Conan Exiles or your server mods. A platform identifier is not a character name or temporary player index.',
];
