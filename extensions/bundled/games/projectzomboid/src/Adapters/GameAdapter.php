<?php

namespace KraftInk\Games\ProjectZomboid\Adapters;

use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'projectzomboid';

    protected string $gameName = 'Project Zomboid';

    protected int $gamePort = 16261;

    protected string $commandTransport = 'source_rcon';
}
