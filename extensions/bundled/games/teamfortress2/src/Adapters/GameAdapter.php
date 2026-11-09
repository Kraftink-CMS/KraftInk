<?php

namespace KraftInk\Games\TeamFortress2\Adapters;

use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'teamfortress2';

    protected string $gameName = 'Team Fortress 2';

    protected int $gamePort = 27015;

    protected string $commandTransport = 'source_rcon';
}
