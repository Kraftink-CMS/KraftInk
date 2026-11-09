<?php

namespace KraftInk\Games\Rust\Adapters;

use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'rust';

    protected string $gameName = 'Rust';

    protected int $gamePort = 28015;

    protected string $commandTransport = 'webrcon';
}
