<?php

namespace KraftInk\Games\Minecraft\Adapters;

use App\Kraft\Games\Contracts\GameAdapter;
use App\Kraft\Games\Contracts\ResolvesPublicProfiles;
use App\Kraft\Games\Contracts\ProvidesAccountNames;
use App\Kraft\Games\Contracts\ProvidesCommandIdentity;
use App\Kraft\Games\Contracts\ProvidesGuestIdentity;
use App\Kraft\Games\Contracts\ProvidesLinkHeartbeatData;
use App\Kraft\Games\Contracts\SupportsInGameSignIn;
use App\Kraft\Games\Contracts\ValidatesDeclaredIdentity;
use App\Kraft\Games\GameProfile;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\PlayerUuid;
use App\Kraft\Settings;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\Minecraft\Services\Auth\BedrockChallenges;
use KraftInk\Games\Minecraft\Services\Auth\BedrockVerificationPolicy;
use KraftInk\Games\Minecraft\Services\Auth\AuthMeRegistrationPolicy;
use KraftInk\Games\Minecraft\Services\Auth\AuthMeRegistrations;
use KraftInk\Games\Minecraft\Services\Profiles\FloodgateProfiles;
use KraftInk\Games\Minecraft\Services\Profiles\MojangProfiles;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\OAuth\OAuthRegistry;

class MinecraftAdapter implements GameAdapter, ResolvesPublicProfiles, ProvidesAccountNames, ProvidesCommandIdentity, ProvidesGuestIdentity, ProvidesLinkHeartbeatData, SupportsInGameSignIn, ValidatesDeclaredIdentity
{
    private const string IN_GAME_JAVA_SOURCE = 'kraftlink_java_session';

    public function publicProfile(string $username): GameProfile
    {
        $profile = $this->profileByUsername($username);

        return new GameProfile($profile['uuid'], $profile['username']);
    }

    public function id(): string
    {
        return 'minecraft';
    }

    public function metadata(): array
    {
        return [
            'name' => 'Minecraft',
            'identity' => 'uuid',
            'description' => 'minecraft::messages.description',
            'capabilities' => [
                'uuid',
                'username',
                'premium_profile',
                'premium_ownership',
                'server_status',
                'server_commands',
                'online_presence',
            ],
            'account_profiles' => false,
            'connection_url' => '/account/connections',
            'connection_adapter' => 'minecraft',
            'settings_sections' => [
                'access' => ['title' => 'minecraft::messages.auth_mode'],
                'bedrock' => ['title' => 'minecraft::messages.floodgate_section', 'description' => 'minecraft::messages.floodgate_help'],
                'in_game_sign_in' => ['title' => 'in-game-sign-in.setting', 'description' => 'in-game-sign-in.setting_help'],
                'authme' => ['title' => 'minecraft::authme.section', 'description' => 'minecraft::authme.description'],
            ],
            'verification_url' => app(Settings::class)->get('games.minecraft.authentication') === 'profile' ? '/minecraft/profile' : null,
            'verification_actions' => [
                [
                    'route' => 'games.minecraft.public.bedrock.start',
                    'label' => 'minecraft::bedrock.action',
                    'available' => BedrockVerificationPolicy::class.'@available',
                ],
            ],
            'default_port' => 25565,
            'connections' => [
                'ping' => 'minecraft::messages.connection_ping',
                'rcon' => 'minecraft::messages.connection_rcon',
                'kraftlink' => 'minecraft::messages.connection_kraftlink',
            ],
        ];
    }

    public function settingsSchema(): array
    {
        return [
            'authentication' => [
                'scope' => 'site_access',
                'section' => 'access',
                'type' => 'choice',
                'label' => 'minecraft::messages.auth_mode',
                'description' => 'minecraft::messages.auth_help',
                'default' => 'open',
                'options' => [
                    'open' => [
                        'label' => 'minecraft::messages.auth_open',
                        'description' => 'minecraft::messages.auth_open_help',
                        'icon' => 'bi-person',
                    ],
                    'profile' => [
                        'label' => app(FloodgateProfiles::class)->enabled()
                            ? 'minecraft::messages.floodgate_auth_profile'
                            : 'minecraft::messages.auth_profile',
                        'description' => app(FloodgateProfiles::class)->enabled()
                            ? 'minecraft::messages.floodgate_profile_help'
                            : 'minecraft::messages.auth_profile_help',
                        'icon' => 'bi-person-badge',
                    ],
                    'premium' => [
                        'label' => 'minecraft::messages.auth_premium',
                        'description' => 'minecraft::messages.auth_premium_help',
                        'icon' => 'bi-shield-check',
                    ],
                ],
            ],
            'floodgate' => [
                'scope' => 'site_access',
                'section' => 'bedrock',
                'type' => 'choice',
                'label' => 'minecraft::messages.floodgate',
                'default' => 'disabled',
                'required' => false,
                'options' => [
                    'disabled' => 'minecraft::messages.disabled',
                    'enabled' => 'minecraft::messages.floodgate_enabled',
                ],
            ],
            'floodgate_prefix' => [
                'scope' => 'site_access',
                'section' => 'bedrock',
                'type' => 'select',
                'label' => 'minecraft::messages.floodgate_prefix',
                'description' => 'minecraft::messages.floodgate_prefix_help',
                'default' => '.',
                'required' => false,
                'when' => ['floodgate' => 'enabled'],
                'options' => [
                    '.' => 'minecraft::messages.floodgate_dot',
                    '+' => 'minecraft::messages.floodgate_plus',
                    '-' => 'minecraft::messages.floodgate_dash',
                ],
            ],
            'premium_bedrock' => [
                'scope' => 'site_access',
                'section' => 'access',
                'label' => 'minecraft::messages.premium_bedrock',
                'description' => 'minecraft::messages.premium_bedrock_help',
                'default' => 'disabled',
                'required' => false,
                'sensitive' => true,
                'when' => ['authentication' => 'premium', 'floodgate' => 'enabled'],
                'options' => [
                    'disabled' => 'minecraft::messages.premium_bedrock_disabled',
                    'enabled' => 'minecraft::messages.premium_bedrock_enabled',
                ],
            ],
            'bedrock_verification_command' => [
                'scope' => 'site_access',
                'section' => 'access',
                'label' => 'minecraft::messages.bedrock_verification_command',
                'description' => 'minecraft::messages.bedrock_verification_command_help',
                'default' => BedrockVerificationPolicy::DEFAULT_COMMAND,
                'required' => false,
                'when' => ['authentication' => 'premium', 'floodgate' => 'enabled', 'premium_bedrock' => 'enabled'],
                'options' => [
                    'link' => 'minecraft::messages.bedrock_verification_command_short',
                    'kraftlink' => 'minecraft::messages.bedrock_verification_command_kraftlink',
                ],
            ],
            'in_game_sign_in' => [
                'scope' => 'site_access',
                'section' => 'in_game_sign_in',
                'label' => 'in-game-sign-in.activation',
                'default' => 'disabled',
                'required' => false,
                'sensitive' => true,
                'options' => ['disabled' => 'in-game-sign-in.disabled', 'enabled' => 'in-game-sign-in.enabled'],
            ],
            'in_game_sign_in_server' => [
                'scope' => 'site_access',
                'section' => 'in_game_sign_in',
                'label' => 'in-game-sign-in.server',
                'description' => 'in-game-sign-in.server_help',
                'default' => '0',
                'required' => false,
                'sensitive' => true,
                'when' => ['in_game_sign_in' => 'enabled'],
                'options' => [0 => 'in-game-sign-in.choose_server'],
                'options_from' => self::class.'@inGameSignInServerOptions',
            ],
            'authme_integration' => [
                'scope' => 'site_access',
                'section' => 'authme',
                'label' => 'minecraft::authme.mode',
                'default' => 'disabled',
                'required' => false,
                'sensitive' => true,
                'options' => [
                    'disabled' => 'minecraft::authme.disabled',
                    'enabled' => 'minecraft::authme.enabled',
                ],
            ],
            'authme_server' => [
                'scope' => 'site_access',
                'section' => 'authme',
                'label' => 'minecraft::authme.server',
                'description' => 'minecraft::authme.server_help',
                'default' => '0',
                'required' => false,
                'sensitive' => true,
                'when' => ['authme_integration' => 'enabled'],
                'options' => [0 => 'minecraft::authme.choose_server'],
                'options_from' => AuthMeRegistrationPolicy::class.'@serverOptions',
            ],
        ];
    }

    public function inGameSignInServerOptions(): array
    {
        return app(InGameSignInPolicy::class)->serverOptions($this->id());
    }

    public function linkHeartbeatData(Server $server): array
    {
        return ['authme_registration' => app(AuthMeRegistrationPolicy::class)->trusts($server)];
    }

    public function inGameSignInName(string $name): ?string
    {
        $profiles = app(FloodgateProfiles::class);
        $name = $profiles->normalizeName(trim($name));

        return $profiles->validName($name) || preg_match('/^[A-Za-z0-9_]{3,16}$/D', $name) === 1
            ? $name
            : null;
    }

    public function inGameSignInProfile(string $uuid, string $name, string $platform): ?array
    {
        $uuid = self::parseUuid($uuid);
        if ($uuid === null) {
            return null;
        }

        $profiles = app(FloodgateProfiles::class);
        if (
            $platform === 'bedrock' && $profiles->validName($name) && $profiles->isFloodgateUuid($uuid) &&
            $this->allowsInGameBedrock()
        ) {
            return ['uuid' => $uuid, 'username' => $name, 'platform' => $platform, 'source' => BedrockChallenges::SOURCE];
        }
        if (
            $platform === 'java' && preg_match('/^[A-Za-z0-9_]{3,16}$/D', $name) === 1 &&
            self::isVerifiedJavaUuid($uuid)
        ) {
            return ['uuid' => $uuid, 'username' => $name, 'platform' => $platform, 'source' => self::IN_GAME_JAVA_SOURCE];
        }

        return null;
    }

    public function supportsInGameSignInIdentity(string $uuid, array $settings = []): bool
    {
        $uuid = self::parseUuid($uuid);

        return $uuid !== null && (self::isVerifiedJavaUuid($uuid) ||
            (app(FloodgateProfiles::class)->isFloodgateUuid($uuid) && $this->allowsInGameBedrock($settings)));
    }

    private function allowsInGameBedrock(array $settings = []): bool
    {
        $current = app(Settings::class);

        return ($settings['floodgate'] ?? $current->get('games.minecraft.floodgate', 'disabled')) === 'enabled' &&
            (($settings['authentication'] ?? $current->get('games.minecraft.authentication', 'open')) !== 'premium' ||
                ($settings['premium_bedrock'] ?? $current->get('games.minecraft.premium_bedrock', 'disabled')) === 'enabled');
    }

    public function identityRules(): array
    {
        $prefix = preg_quote(app(FloodgateProfiles::class)->prefix(), '/');
        $pattern = app(FloodgateProfiles::class)->enabled()
            ? '(?:[A-Za-z0-9_]{3,16}|'.$prefix.'[A-Za-z0-9_ ]{1,16})'
            : '[A-Za-z0-9_]{3,16}';

        return [
            'username' => ['required', 'string', 'regex:/^'.$pattern.'$/D'],
            'uuid' => 'required|string|max:36',
        ];
    }

    public function accountNamePresentation(): array
    {
        $default = '[A-Za-z0-9][A-Za-z0-9_.\\-]{1,39}';
        $profiles = app(FloodgateProfiles::class);

        return [
            'pattern' => $profiles->enabled()
                ? '(?:'.$default.'|'.AccountIdentity::literalPattern($profiles->prefix()).'[A-Za-z0-9_ ]{1,16})'
                : $default,
            'help' => $profiles->enabled()
                ? __('minecraft::messages.floodgate_account_help', ['prefix' => $profiles->prefix()])
                : __('site.auth.account.username_help'),
        ];
    }

    public function normalizeAccountName(string $name): string
    {
        return app(FloodgateProfiles::class)->normalizeName($name);
    }

    public function profileByUsername(string $username): array
    {
        $profiles = app(FloodgateProfiles::class);

        return $profiles->isBedrockName($username)
            ? $profiles->byUsername($username)
            : app(MojangProfiles::class)->byUsername($username);
    }

    public function profileByUuid(string $uuid, ?string $username = null): array
    {
        $profiles = app(FloodgateProfiles::class);
        $uuid = $this->normalizeUuid($uuid);
        if ($profiles->enabled() && $profiles->isFloodgateUuid($uuid)) {
            if (!$username) {
                throw ValidationException::withMessages([
                    'username' => __('minecraft::messages.floodgate_name_required'),
                ]);
            }

            return $profiles->byUsername($username, $uuid);
        }

        return app(MojangProfiles::class)->byUuid($uuid);
    }

    public function declaredIdentity(array $data): array
    {
        $profiles = app(FloodgateProfiles::class);
        $name = is_string($data['username'] ?? null) ? $profiles->normalizeName($data['username']) : '';
        $uuid = $this->normalizeUuid($data['uuid'] ?? '');
        if ($profiles->isBedrockName($name) || ($profiles->enabled() && $profiles->isFloodgateUuid($uuid))) {
            return $profiles->byUsername($name, $uuid);
        }
        if (preg_match('/^[A-Za-z0-9_]{3,16}$/D', $name) !== 1) {
            throw ValidationException::withMessages(['username' => __('minecraft::messages.floodgate_invalid_name')]);
        }

        return ['uuid' => $uuid, 'username' => $name];
    }

    public function commandIdentity(string $uuid, ?string $username): array
    {
        $profiles = app(FloodgateProfiles::class);
        $name = $profiles->normalizeName($username ?? '');
        $canonical = $uuid !== '' ? $this->normalizeUuid($uuid) : '';
        if ($canonical !== '' && $profiles->isFloodgateUuid($canonical) && !$profiles->isBedrockName($name)) {
            throw ValidationException::withMessages(['username' => __('minecraft::messages.floodgate_name_required')]);
        }
        if ($profiles->isBedrockName($name)) {
            $profile = $profiles->byUsername($name, $canonical !== '' ? $canonical : null);

            return ['player' => $profile['username'], 'uuid' => $canonical !== '' ? $profile['uuid'] : ''];
        }
        if ($name === '' && $canonical !== '') {
            return ['player' => '', 'uuid' => $canonical];
        }
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/D', $name) !== 1) {
            throw ValidationException::withMessages(['username' => __('minecraft::messages.floodgate_invalid_name')]);
        }

        return ['player' => $name, 'uuid' => $canonical];
    }

    public function guestIdentity(string $input): array
    {
        return $this->commandIdentity('', $input);
    }

    public function normalizeUuid(string $uuid): string
    {
        return self::parseUuid($uuid) ?? throw ValidationException::withMessages(['uuid' => __('site.games.invalid_uuid')]);
    }

    public static function parseUuid(string $uuid): ?string
    {
        return PlayerUuid::compact($uuid);
    }

    public static function isVerifiedJavaUuid(?string $uuid): bool
    {
        return $uuid !== null && preg_match('/^[a-f0-9]{12}4[a-f0-9]{3}[89ab][a-f0-9]{15}$/D', $uuid) === 1;
    }

    public function assertCanAuthenticate(User $user, ?GameIdentity $identity, Settings $settings): void
    {
        $mode = $settings->get('games.minecraft.authentication', 'open');
        if ($mode === 'profile') {
            $this->assertProfile($identity);
        }
        if ($mode === 'premium' && !$this->hasPremiumIdentity($identity, app(BedrockVerificationPolicy::class)->enabled())) {
            throw ValidationException::withMessages(['email' => __('minecraft::messages.premium_required')]);
        }
    }

    public function validateSettings(User $user, array $settings, Settings $current): void
    {
        if (
            ($settings['in_game_sign_in'] ?? $current->get('games.minecraft.in_game_sign_in')) === 'enabled' &&
            !app(InGameSignInPolicy::class)->findServer(
                $this->id(),
                $settings['in_game_sign_in_server'] ?? $current->get('games.minecraft.in_game_sign_in_server'),
            )
        ) {
            throw ValidationException::withMessages([
                'game_settings.minecraft.in_game_sign_in_server' => __('in-game-sign-in.server_required'),
            ]);
        }
        if (
            ($settings['authme_integration'] ?? $current->get('games.minecraft.authme_integration')) === 'enabled' &&
            !app(AuthMeRegistrationPolicy::class)->findServer($settings['authme_server'] ?? $current->get('games.minecraft.authme_server'))
        ) {
            throw ValidationException::withMessages([
                'game_settings.minecraft.authme_server' => __('minecraft::authme.server_required'),
            ]);
        }

        $mode = $settings['authentication'] ?? 'open';
        if (!in_array($mode, ['profile', 'premium'], true)) {
            return;
        }
        $identity = app(GameRegistry::class)->identityFor($user, $this->id());
        if ($mode === 'profile') {
            if (
                $identity &&
                app(FloodgateProfiles::class)->isFloodgateUuid($identity->uuid) &&
                (($settings['floodgate'] ?? $current->get('games.minecraft.floodgate', 'disabled')) !== 'enabled' ||
                    !str_starts_with(
                        $identity->username ?? '',
                        $settings['floodgate_prefix'] ?? $current->get('games.minecraft.floodgate_prefix', '.'),
                    ))
            ) {
                throw ValidationException::withMessages([
                    'game_settings.minecraft.floodgate' => __('minecraft::messages.floodgate_keep_access'),
                ]);
            }
            $this->assertProfile($identity);

            return;
        }
        $bedrockSelected = ($settings['premium_bedrock'] ?? $current->get('games.minecraft.premium_bedrock')) === 'enabled';
        $floodgate = ($settings['floodgate'] ?? $current->get('games.minecraft.floodgate')) === 'enabled';
        if ($bedrockSelected && !$floodgate && $current->get('games.minecraft.premium_bedrock') !== 'enabled') {
            throw ValidationException::withMessages([
                'game_settings.minecraft.premium_bedrock' => __('minecraft::messages.premium_bedrock_requires_floodgate'),
            ]);
        }
        $bedrock = $bedrockSelected && $floodgate;
        if ($bedrock && !app(BedrockVerificationPolicy::class)->hasLinkedServers()) {
            throw ValidationException::withMessages([
                'game_settings.minecraft.premium_bedrock' => __('minecraft::messages.bedrock_verification_connector_required'),
            ]);
        }
        if (!app(OAuthRegistry::class)->enabledForAdapter('minecraft') || !$this->hasPremiumIdentity($identity, $bedrock)) {
            throw ValidationException::withMessages([
                'game_settings.minecraft.authentication' => __('minecraft::messages.premium_setup_required'),
            ]);
        }
    }

    private function hasPremiumIdentity(?GameIdentity $identity, bool $bedrock): bool
    {
        if (!$identity?->verified_at) {
            return false;
        }

        if (($identity->metadata['source'] ?? null) === AuthMeRegistrations::SOURCE) {
            return self::isVerifiedJavaUuid($identity->uuid);
        }

        return !app(FloodgateProfiles::class)->isFloodgateUuid($identity->uuid) ||
            ($bedrock && ($identity->metadata['source'] ?? null) === BedrockChallenges::SOURCE);
    }

    private function assertProfile(?GameIdentity $identity): void
    {
        $uuid = $identity?->uuid;
        if (!$uuid) {
            throw ValidationException::withMessages([
                'email' => __(
                    app(FloodgateProfiles::class)->enabled()
                        ? 'minecraft::messages.floodgate_profile_required'
                        : 'minecraft::messages.profile_required',
                ),
            ]);
        }
        $source = $identity->metadata['source'] ?? null;
        $profiles = app(FloodgateProfiles::class);
        if ($identity->verified_at && (
            $source === self::IN_GAME_JAVA_SOURCE ||
            ($source === AuthMeRegistrations::SOURCE && self::isVerifiedJavaUuid($uuid)) ||
            ($source === BedrockChallenges::SOURCE && $profiles->enabled() && $profiles->isFloodgateUuid($uuid))
        )) {
            return;
        }
        $this->profileByUuid($uuid, $identity->username);
    }
}
