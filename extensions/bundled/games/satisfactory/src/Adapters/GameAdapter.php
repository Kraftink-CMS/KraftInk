<?php

namespace KraftInk\Games\Satisfactory\Adapters;

use App\Kraft\Games\DeclaredGameAdapter;
use Illuminate\Validation\ValidationException;

class GameAdapter extends DeclaredGameAdapter
{
    protected string $gameId = 'satisfactory';

    protected string $gameName = 'Satisfactory';

    protected string $identityLabel = 'satisfactory::messages.identity';

    protected int $gamePort = 7777;

    protected array $connections = [
        'manual' => 'satisfactory::messages.manual',
        'https_api' => 'satisfactory::messages.https_api',
    ];

    public function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^(?:steam:[0-9]{17}|epic:[a-f0-9]{32})$/D', $uuid)) {
            throw ValidationException::withMessages(['uuid' => __('satisfactory::messages.invalid_identity')]);
        }
        if (
            str_starts_with($uuid, 'steam:') &&
            (strcmp(substr($uuid, 6), '76561197960265728') < 0 || strcmp(substr($uuid, 6), '76561202255233023') > 0)
        ) {
            throw ValidationException::withMessages(['uuid' => __('satisfactory::messages.invalid_identity')]);
        }

        return $uuid;
    }
}
