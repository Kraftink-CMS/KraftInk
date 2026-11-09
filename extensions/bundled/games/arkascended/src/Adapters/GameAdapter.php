<?php

namespace KraftInk\Games\ArkAscended\Adapters;

use App\Kraft\Games\DeclaredGameAdapter;
use Illuminate\Validation\ValidationException;

class GameAdapter extends DeclaredGameAdapter
{
    protected string $gameId = 'arkascended';

    protected string $gameName = 'ARK: Survival Ascended';

    protected string $identityLabel = 'arkascended::messages.identity';

    protected int $gamePort = 7777;

    protected array $connections = [
        'manual' => 'arkascended::messages.manual',
        'rcon' => 'arkascended::messages.rcon',
    ];

    public function metadata(): array
    {
        return array_replace(parent::metadata(), ['capabilities' => ['uuid', 'username', 'online_presence']]);
    }

    public function normalizeUuid(string $uuid): string
    {
        $value = strtolower($uuid);
        if (!preg_match('/^[a-f0-9]{32}$/D', $value) || trim($value, '0') === '') {
            throw ValidationException::withMessages(['uuid' => __('arkascended::messages.invalid_identity')]);
        }

        return $value;
    }
}
