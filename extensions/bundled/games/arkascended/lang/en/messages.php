<?php

return [
    'description' => 'Declared EOS identities and authenticated RCON commands. ListPlayers supplies the player count and EOS presence for pending deliveries without publishing names or IDs. The maximum player count is not supplied.',
    'identity' => 'ARK EOS identifier',
    'invalid_identity' => 'Enter a nonzero EOS identifier containing 32 hexadecimal characters.',
    'manual' => 'No connection',
    'rcon' => 'Source RCON',
    'rcon_help' => 'Enable RCONEnabled, set RCONPort and ServerAdminPassword in GameUserSettings.ini. The default TCP port is 27020. Status uses the authenticated ListPlayers command. Command targets use the EOS identifier, not a SteamID.',
];
