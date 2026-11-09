<?php

namespace KraftInk\Games\MinecraftBedrock\Adapters;

use App\Kraft\Games\Contracts\ValidatesDeclaredIdentity;
use App\Kraft\Games\DeclaredGameAdapter;
use Illuminate\Validation\ValidationException;

class BedrockAdapter extends DeclaredGameAdapter implements ValidatesDeclaredIdentity
{
    protected string $gameId = 'minecraftbedrock';

    protected string $gameName = 'Minecraft Bedrock';

    protected string $identityLabel = 'minecraftbedrock::messages.gamertag';

    protected int $gamePort = 19132;

    protected array $connections = [
        'ping' => 'minecraftbedrock::messages.connection_ping',
        'kraftlink' => 'minecraftbedrock::messages.connection_kraftlink',
    ];

    public function metadata(): array
    {
        return array_replace(parent::metadata(), [
            'identity' => 'username',
            'username_identity' => true,
            'username_label' => $this->identityLabel,
            'capabilities' => ['username', 'server_status', 'server_commands', 'online_presence'],
        ]);
    }

    public function identityRules(): array
    {
        return ['username' => ['required', 'string', 'max:24'], 'uuid' => ['prohibited']];
    }

    public function declaredIdentity(array $data): array
    {
        $username = trim($data['username']);

        return ['username' => $username, 'uuid' => $this->normalizeUuid($username)];
    }

    public function normalizeUuid(string $uuid): string
    {
        $gamertag = trim($uuid);
        if (
            mb_strlen($gamertag) > 24 || strlen($gamertag) > 60 ||
            !preg_match('/^[\p{L}\p{N}][\p{L}\p{M}\p{N}]*(?: [\p{L}\p{M}\p{N}]+)*(?:#[0-9]{1,5})?$/uD', $gamertag)
        ) {
            throw ValidationException::withMessages(['username' => __('minecraftbedrock::messages.invalid_gamertag')]);
        }

        return mb_strtolower($gamertag);
    }

    public function commandIdentity(string $uuid, ?string $username): array
    {
        $identity = $this->normalizeUuid($uuid);
        $player = $username !== null && $this->normalizeUuid($username) === $identity ? trim($username) : trim($uuid);

        return ['uuid' => $identity, 'player' => $player];
    }
}
