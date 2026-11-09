<?php

namespace KraftInk\Games\GarrysMod\Adapters;

use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'garrysmod';

    protected string $gameName = "Garry's Mod";

    protected int $gamePort = 27015;

    protected string $commandTransport = 'source_rcon';
}
