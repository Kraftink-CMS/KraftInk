<?php

namespace KraftInk\Games\Unturned\Adapters;

use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'unturned';

    protected string $gameName = 'Unturned';

    protected int $gamePort = 27015;

    protected string $commandTransport = 'source_rcon';
}
