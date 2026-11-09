<?php

namespace KraftInk\Games\CounterStrike2\Adapters;

use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'counterstrike2';

    protected string $gameName = 'Counter-Strike 2';

    protected int $gamePort = 27015;

    protected string $commandTransport = 'source_rcon';
}
