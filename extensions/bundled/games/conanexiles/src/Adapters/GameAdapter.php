<?php

namespace KraftInk\Games\ConanExiles\Adapters;

use App\Kraft\Games\DeclaredGameAdapter;
use Illuminate\Validation\ValidationException;

class GameAdapter extends DeclaredGameAdapter
{
    protected string $gameId = 'conanexiles';

    protected string $gameName = 'Conan Exiles';

    protected string $identityLabel = 'conanexiles::messages.identity';

    protected int $gamePort = 7777;

    protected array $connections = [
        'manual' => 'conanexiles::messages.manual',
        'query' => 'conanexiles::messages.query',
        'rcon' => 'conanexiles::messages.rcon',
    ];

    public function normalizeUuid(string $uuid): string
    {
        $value = strtolower($uuid);
        $prefix = null;
        if (preg_match('/^(steam|epic|eos):(.*)$/D', $value, $parts)) {
            $prefix = $parts[1];
            $value = $parts[2];
        }
        $steam =
            preg_match('/^[0-9]{17}$/D', $value) &&
            strcmp($value, '76561197960265728') >= 0 &&
            strcmp($value, '76561202255233023') <= 0;
        $eos = preg_match('/^[a-f0-9]{32}$/D', $value) && trim($value, '0') !== '';
        if (
            !(($prefix === null || $prefix === 'steam') && $steam) &&
            !(($prefix === null || in_array($prefix, ['eos', 'epic'], true)) && $eos)
        ) {
            throw ValidationException::withMessages(['uuid' => __('conanexiles::messages.invalid_identity')]);
        }

        return $value;
    }
}
