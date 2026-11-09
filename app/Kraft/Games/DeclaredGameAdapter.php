<?php

namespace App\Kraft\Games;

use App\Kraft\Games\Contracts\GameAdapter;
use App\Kraft\Games\Contracts\ProvidesCommandIdentity;
use App\Kraft\Games\Contracts\ProvidesGuestIdentity;
use App\Kraft\Settings;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;

abstract class DeclaredGameAdapter implements GameAdapter, ProvidesCommandIdentity, ProvidesGuestIdentity
{
    protected string $gameId;

    protected string $gameName;

    protected string $identityLabel;

    protected int $gamePort;

    protected array $connections = [];

    public function id(): string
    {
        return $this->gameId;
    }

    public function metadata(): array
    {
        return [
            'name' => $this->gameName,
            'identity' => 'game_id',
            'identity_label' => $this->identityLabel,
            'description' => $this->gameId.'::messages.description',
            'capabilities' => ['uuid', 'username'],
            'connection_url' => null,
            'default_port' => $this->gamePort,
            'connections' => $this->connections,
        ];
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function identityRules(): array
    {
        return ['username' => 'required|string|min:1|max:60', 'uuid' => 'required|string|max:64'];
    }

    public function commandIdentity(string $uuid, ?string $username): array
    {
        $identifier = $this->normalizeUuid($uuid);

        return ['uuid' => $identifier, 'player' => $identifier];
    }

    public function guestIdentity(string $input): array
    {
        return $this->commandIdentity($input, null);
    }

    public function assertCanAuthenticate(User $user, ?GameIdentity $identity, Settings $settings): void
    {
    }

    public function validateSettings(User $user, array $settings, Settings $current): void
    {
    }
}
