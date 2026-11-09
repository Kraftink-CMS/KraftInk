<?php

namespace KraftInk\Games\DayZ\Adapters;

use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'dayz';

    protected string $gameName = 'DayZ';

    protected int $gamePort = 2302;

    protected string $commandTransport = 'battleye';
}
