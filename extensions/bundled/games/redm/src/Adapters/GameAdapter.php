<?php

namespace KraftInk\Games\RedM\Adapters;

use App\Kraft\Games\DeclaredGameAdapter;
use Illuminate\Validation\ValidationException;

class GameAdapter extends DeclaredGameAdapter
{
    protected string $gameId = 'redm';

    protected string $gameName = 'RedM';

    protected string $identityLabel = 'redm::messages.identity';

    protected int $gamePort = 30120;

    protected array $connections = [
        'manual' => 'redm::messages.manual',
        'http' => 'redm::messages.query',
        'fxserver_rcon' => 'redm::messages.rcon',
    ];

    public function normalizeUuid(string $uuid): string
    {
        $value = strtolower($uuid);
        if (
            !preg_match('/^(?:(?:license|license2):[a-f0-9]{40}|fivem:[1-9][0-9]{0,19})$/D', $value) ||
            preg_match('/^license2?:0{40}$/D', $value)
        ) {
            throw ValidationException::withMessages(['uuid' => __('redm::messages.invalid_identity')]);
        }

        return $value;
    }
}
