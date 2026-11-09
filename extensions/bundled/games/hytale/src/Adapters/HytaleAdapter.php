<?php

namespace KraftInk\Games\Hytale\Adapters;

use App\Kraft\Games\Contracts\GameAdapter;
use App\Kraft\Games\Contracts\PublicProfileAvailability;
use App\Kraft\Games\Contracts\ResolvesPublicProfiles;
use App\Kraft\Games\GameProfile;
use App\Kraft\Games\PlayerUuid;
use App\Kraft\Settings;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\Hytale\Services\Profiles\PlayerDbProfiles;

class HytaleAdapter implements GameAdapter, PublicProfileAvailability, ResolvesPublicProfiles
{
    public function publicProfilesAvailable(): bool
    {
        return app(PlayerDbProfiles::class)->enabled();
    }

    public function publicProfile(string $username): GameProfile
    {
        $profile = app(PlayerDbProfiles::class)->lookup($username);

        return new GameProfile($profile['uuid'], $profile['username']);
    }

    public function id(): string
    {
        return 'hytale';
    }

    public function metadata(): array
    {
        return [
            'name' => 'Hytale',
            'identity' => 'uuid',
            'description' => 'hytale::messages.description',
            'capabilities' => ['uuid', 'username', 'server_status', 'server_commands', 'online_presence'],
            'connection_url' => null,
            'default_port' => 5520,
            'connections' => [
                'manual' => 'hytale::messages.connection_manual',
                'kraftlink' => 'hytale::messages.connection_kraftlink',
            ],
        ];
    }

    public function settingsSchema(): array
    {
        return [
            'public_profiles' => [
                'type' => 'choice',
                'label' => 'hytale::messages.public_profiles',
                'default' => 'disabled',
                'options' => [
                    'disabled' => [
                        'label' => 'hytale::messages.disabled',
                        'description' => 'hytale::messages.public_profiles_disabled_help',
                    ],
                    'enabled' => [
                        'label' => 'hytale::messages.playerdb_enabled',
                        'description' => 'hytale::messages.public_profiles_enabled_help',
                    ],
                ],
            ],
        ];
    }

    public function identityRules(): array
    {
        return ['username' => 'required|string|min:2|max:60', 'uuid' => 'required|string|max:36'];
    }

    public function normalizeUuid(string $uuid): string
    {
        return self::parseUuid($uuid) ?? throw ValidationException::withMessages(['uuid' => __('site.games.invalid_uuid')]);
    }

    public static function parseUuid(string $uuid): ?string
    {
        return PlayerUuid::hyphenated($uuid);
    }

    public function assertCanAuthenticate(User $user, ?GameIdentity $identity, Settings $settings): void
    {
    }

    public function validateSettings(User $user, array $settings, Settings $current): void
    {
    }
}
