<?php

namespace KraftInk\Games\SevenDaysToDie\Adapters;

use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'sevendaystodie';

    protected string $gameName = '7 Days to Die';

    protected int $gamePort = 26900;

    protected string $commandTransport = 'telnet';
}
