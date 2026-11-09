<?php

return [
    'steam_id' => 'SteamID64',
    'manual' => 'No connection',
    'query' => 'Source Query',
    'http' => 'HTTP',
    'source_rcon' => 'Source RCON',
    'webrcon' => 'WebRCON',
    'fivem_rcon' => 'FXServer RCON',
    'battleye' => 'BattlEye RCON',
    'telnet' => 'Telnet',
    'authentication' => 'Website account access',
    'open' => 'Standard account',
    'open_help' => 'Members use the website sign-in methods. Self-declared identities remain unverified.',
    'verified' => 'Verified Steam account',
    'verified_help' => 'Link Steam to prove your identity. This connection does not verify game ownership.',
    'identity_required' => 'Link your Steam account to access this website.',
    'setup_required' => 'Enable Steam and verify your own identity for this game before requiring this protection.',
    'provider_required' => 'Keep a Steam connection enabled or first disable the verified Steam account requirement.',
    'invalid_id' => 'Enter a valid individual SteamID64, SteamID2, SteamID3 or hexadecimal steam: identifier.',
    'api_key' => 'Optional Steam Web API key',
    'avatar' => 'Steam profile avatar, using a configured Web API key. Locally uploaded avatars retain priority.',
    'profile_unavailable' => 'The Steam profile is unavailable. A Steam Web API key is required to read public profiles.',
    'oauth' => [
        'description' => 'Steam uses OpenID: no application ID is required. Enable this service to allow Steam sign-in and account linking.',
        'note' => 'The optional Web API key retrieves public profile names and avatars. Steam does not share an email address: members enter it separately.',
    ],
];
