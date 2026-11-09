<?php

namespace KraftInk\Games\MinecraftBedrock\Adapters;

use App\Kraft\Games\Contracts\FiltersLinkCommandTargets;
use App\Kraft\Games\Contracts\PublicProfileAvailability;
use App\Kraft\Games\Contracts\ResolvesPublicProfiles;
use App\Kraft\Games\Contracts\ValidatesDeclaredIdentity;
use App\Kraft\Games\DeclaredGameAdapter;
use App\Kraft\Games\GameProfile;
use App\Kraft\Games\IdentityVerificationCommand;
use App\Kraft\Games\PlayerUuid;
use App\Kraft\Settings;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\MinecraftBedrock\Services\IdentityVerification;
use KraftInk\Games\MinecraftBedrock\Services\PublicProfiles;

class BedrockAdapter extends DeclaredGameAdapter implements ValidatesDeclaredIdentity, ResolvesPublicProfiles, PublicProfileAvailability, FiltersLinkCommandTargets
{
    private const string XUID_PREFIX = '0000000000000000';

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
        $ready = app(IdentityVerification::class)->ready();

        return array_replace(parent::metadata(), [
            'identity' => 'username',
            'username_identity' => true,
            'username_label' => $this->identityLabel,
            'capabilities' => ['username', 'account_verification', 'server_status', 'server_commands', 'online_presence'],
            'verified_profile_ready' => $ready,
            'verified_profile_help' => 'minecraftbedrock::messages.verified_profile_help',
            'connection_url' => $ready ? '/account/games/bedrock/verify' : null,
            'settings_sections' => ['identity' => ['title' => 'minecraftbedrock::messages.identity_settings']],
        ]);
    }

    public function settingsSchema(): array
    {
        $switch = ['disabled' => 'common.actions.disable', 'enabled' => 'common.actions.enable'];

        return [
            'identity_verification' => [
                'section' => 'identity',
                'label' => 'minecraftbedrock::messages.identity_verification',
                'description' => 'minecraftbedrock::messages.identity_verification_help',
                'default' => 'disabled',
                'required' => false,
                'sensitive' => true,
                'options' => $switch,
            ],
            'identity_verification_server' => [
                'section' => 'identity',
                'label' => 'minecraftbedrock::messages.identity_verification_server',
                'description' => 'minecraftbedrock::messages.identity_verification_server_help',
                'default' => '0',
                'required' => false,
                'sensitive' => true,
                'when' => ['identity_verification' => 'enabled'],
                'options' => [0 => 'minecraftbedrock::messages.identity_choose_server'],
                'options_from' => IdentityVerification::class.'@serverOptions',
            ],
            'identity_verification_command' => [
                'section' => 'identity',
                'when' => ['identity_verification' => 'enabled'],
            ] + IdentityVerificationCommand::field(),
            'public_profiles' => [
                'section' => 'identity',
                'label' => 'minecraftbedrock::messages.public_profiles',
                'description' => 'minecraftbedrock::messages.public_profiles_help',
                'default' => 'disabled',
                'required' => false,
                'options' => $switch,
            ],
        ];
    }

    public function validateSettings(User $user, array $settings, Settings $current): void
    {
        if (($settings['identity_verification'] ?? 'disabled') === 'enabled' &&
            !array_key_exists($settings['identity_verification_server'] ?? '0', app(IdentityVerification::class)->serverOptions())) {
            throw ValidationException::withMessages([
                'game_settings.minecraftbedrock.identity_verification_server' => __('minecraftbedrock::messages.identity_server_required'),
            ]);
        }
    }

    public function publicProfilesAvailable(): bool
    {
        return app(PublicProfiles::class)->enabled();
    }

    public function publicProfile(string $username): GameProfile
    {
        return app(PublicProfiles::class)->byGamertag($username);
    }

    public function identityRules(): array
    {
        return ['username' => ['required', 'string', 'max:24'], 'uuid' => ['prohibited']];
    }

    public function declaredIdentity(array $data): array
    {
        $username = $this->gamertag($data['username']);
        if ($this->publicProfilesAvailable()) {
            $this->publicProfile($username);
        }

        return ['username' => $username, 'uuid' => mb_strtolower($username)];
    }

    public function verifiedProfile(string $uuid, string $username): array
    {
        return ['uuid' => $this->xuidUuid(PlayerUuid::compact(trim($uuid))), 'username' => $this->gamertag($username)];
    }

    public function normalizeUuid(string $uuid): string
    {
        $compact = PlayerUuid::compact(trim($uuid));

        return $compact === null ? mb_strtolower($this->gamertag($uuid)) : $this->xuidUuid($compact);
    }

    public function commandIdentity(string $uuid, ?string $username): array
    {
        $identity = $this->normalizeUuid($uuid);
        if (PlayerUuid::compact($identity) !== null) {
            return ['uuid' => $identity, 'player' => $username === null ? '' : $this->gamertag($username)];
        }
        $player = $username !== null && $this->normalizeUuid($username) === $identity ? trim($username) : trim($uuid);

        return ['uuid' => $identity, 'player' => $player];
    }

    public function filterLinkCommandTargets(Builder $query, ?string $capability): void
    {
        if ($capability !== 'xbox-xuid') {
            $pattern = self::XUID_PREFIX.str_repeat('_', 16);
            $query->where(fn (Builder $targets) => $targets->whereNull('player_uuid')->orWhere('player_uuid', 'not like', $pattern));
        }
    }

    private function gamertag(string $username): string
    {
        $gamertag = trim($username);
        if (
            mb_strlen($gamertag) > 24 || strlen($gamertag) > 60 ||
            !preg_match('/^[\p{L}\p{N}][\p{L}\p{M}\p{N}]*(?: [\p{L}\p{M}\p{N}]+)*(?:#[0-9]{1,5})?$/uD', $gamertag)
        ) {
            throw ValidationException::withMessages(['username' => __('minecraftbedrock::messages.invalid_gamertag')]);
        }

        return $gamertag;
    }

    private function xuidUuid(?string $uuid): string
    {
        if ($uuid === null || !str_starts_with($uuid, self::XUID_PREFIX) || $uuid === str_repeat('0', 32)) {
            throw ValidationException::withMessages(['uuid' => __('minecraftbedrock::messages.invalid_xuid')]);
        }

        return $uuid;
    }
}
