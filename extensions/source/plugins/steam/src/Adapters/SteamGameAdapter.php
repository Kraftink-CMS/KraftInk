<?php

namespace KraftInk\Integrations\Steam\Adapters;

use App\Kraft\Games\Contracts\GameAdapter;
use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Settings;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;
use Illuminate\Validation\ValidationException;
use KraftInk\Integrations\Steam\Services\Profiles\SteamIdentity;
use KraftInk\Integrations\Steam\Services\Profiles\SteamProfiles;
use App\Kraft\Games\Contracts\ProvidesCommandIdentity;
use App\Kraft\Games\Contracts\ProvidesGuestIdentity;
use App\Kraft\Games\Contracts\PublicProfileAvailability;
use App\Kraft\Games\Contracts\ResolvesPublicProfiles;
use App\Kraft\Games\GameProfile;

abstract class SteamGameAdapter implements GameAdapter, PublicProfileAvailability, ResolvesPublicProfiles, ProvidesCommandIdentity, ProvidesGuestIdentity
{
    public const array COMMAND_TOKENS = [...ProvidesCommandIdentity::COMMAND_TOKENS, 'steam_id', 'steam_id_32'];

    protected string $gameId;

    protected string $gameName;

    protected int $gamePort;

    protected string $statusTransport = 'query';

    protected string $commandTransport;

    public function usesSteamIdentity(): bool
    {
        return true;
    }

    public function publicProfilesAvailable(): bool
    {
        return app(SteamProfiles::class)->key() !== null;
    }

    public function commandIdentity(string $uuid, ?string $username): array
    {
        $id = SteamIdentity::normalize($uuid);

        return [
            'uuid' => $id,
            'player' => $id,
            'steam_id' => $id,
            'steam_id_32' => (string) ((int) $id - 76561197960265728),
        ];
    }

    public function guestIdentity(string $input): array
    {
        return $this->commandIdentity($input, null);
    }

    public function publicProfile(string $username): GameProfile
    {
        $profile = app(SteamProfiles::class)->lookup($username);

        return new GameProfile($profile['steamid'], $profile['personaname']);
    }

    public function id(): string
    {
        return $this->gameId;
    }

    public function metadata(): array
    {
        return [
            'name' => $this->gameName,
            'identity' => 'steam_id',
            'identity_label' => 'steam::messages.steam_id',
            'description' => $this->gameId.'::messages.description',
            'capabilities' => ['uuid', 'username', 'account_verification'],
            'connection_url' => '/account/connections',
            'connection_adapter' => 'steam',
            'default_port' => $this->gamePort,
            'connections' => [
                'manual' => 'steam::messages.manual',
                $this->statusTransport => 'steam::messages.'.$this->statusTransport,
                $this->commandTransport => 'steam::messages.'.$this->commandTransport,
            ],
        ];
    }

    public function settingsSchema(): array
    {
        return [
            'authentication' => [
                'type' => 'choice',
                'label' => 'steam::messages.authentication',
                'default' => 'open',
                'options' => [
                    'open' => ['label' => 'steam::messages.open', 'description' => 'steam::messages.open_help'],
                    'steam' => [
                        'label' => 'steam::messages.verified',
                        'description' => 'steam::messages.verified_help',
                    ],
                ],
            ],
        ];
    }

    public function identityRules(): array
    {
        return ['username' => 'required|string|min:1|max:60', 'uuid' => 'required|string|max:64'];
    }

    public function normalizeUuid(string $uuid): string
    {
        return SteamIdentity::normalize($uuid);
    }

    public function assertCanAuthenticate(User $user, ?GameIdentity $identity, Settings $settings): void
    {
        if (
            $settings->get('games.'.$this->gameId.'.authentication', 'open') === 'steam' &&
            !$identity?->verified_at
        ) {
            throw ValidationException::withMessages(['game' => __('steam::messages.identity_required')]);
        }
    }

    public function validateSettings(User $user, array $settings, Settings $current): void
    {
        if (
            ($settings['authentication'] ?? 'open') === 'steam' &&
            (!app(OAuthRegistry::class)->enabledForAdapter('steam') ||
                !app(GameRegistry::class)->identityFor($user, $this->gameId)?->verified_at)
        ) {
            throw ValidationException::withMessages(['game' => __('steam::messages.setup_required')]);
        }
    }
}
