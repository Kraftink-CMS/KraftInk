<?php

namespace KraftInk\Games\Ark\Adapters;

use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'ark';

    protected string $gameName = 'ARK: Survival Evolved';

    protected int $gamePort = 7777;

    protected string $commandTransport = 'source_rcon';
}
