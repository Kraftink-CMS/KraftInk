<?php

namespace KraftInk\Games\Palworld\Adapters;

use App\Kraft\Games\DeclaredGameAdapter;
use Illuminate\Validation\ValidationException;

class GameAdapter extends DeclaredGameAdapter
{
    protected string $gameId = 'palworld';

    protected string $gameName = 'Palworld';

    protected string $identityLabel = 'palworld::messages.identity';

    protected int $gamePort = 8211;

    protected array $connections = ['manual' => 'palworld::messages.manual', 'rest' => 'palworld::messages.rest'];

    public function metadata(): array
    {
        return array_replace(parent::metadata(), ['capabilities' => ['uuid', 'username', 'online_presence']]);
    }

    public function normalizeUuid(string $uuid): string
    {
        $uuid = trim($uuid);
        if (!self::isValidUserId($uuid)) {
            throw ValidationException::withMessages(['uuid' => __('palworld::messages.invalid_identity')]);
        }

        return $uuid;
    }

    public static function isValidUserId(string $id): bool
    {
        return strlen($id) <= 60 && preg_match('/^[a-z][a-z0-9]{0,15}_[a-zA-Z0-9]{1,46}$/D', $id) === 1;
    }
}
