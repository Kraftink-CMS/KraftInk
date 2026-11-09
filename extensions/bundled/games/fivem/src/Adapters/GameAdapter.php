<?php

namespace KraftInk\Games\FiveM\Adapters;

use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Settings;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;
use Illuminate\Validation\ValidationException;
use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;

class GameAdapter extends SteamGameAdapter
{
    protected string $gameId = 'fivem';

    protected string $gameName = 'FiveM';

    protected int $gamePort = 30120;

    protected string $statusTransport = 'http';

    protected string $commandTransport = 'fivem_rcon';

    public const array COMMAND_TOKENS = [...parent::COMMAND_TOKENS, 'cfx_id'];

    public function usesSteamIdentity(): bool
    {
        return app(Settings::class)->get('games.fivem.identity_provider', 'cfx') === 'steam';
    }

    public function metadata(): array
    {
        $data = parent::metadata();
        if (!$this->usesSteamIdentity()) {
            $data['identity'] = 'cfx_id';
            $data['identity_label'] = 'fivem::messages.cfx_id';
            $data['connection_adapter'] = 'cfx';
        }

        return $data;
    }

    public function settingsSchema(): array
    {
        return [
            'identity_provider' => [
                'type' => 'choice',
                'label' => 'fivem::messages.identity_provider',
                'default' => 'cfx',
                'sensitive' => true,
                'options' => ['cfx' => ['label' => 'Cfx.re'], 'steam' => ['label' => 'Steam']],
            ],
            'authentication' => [
                'type' => 'choice',
                'label' => 'steam::messages.authentication',
                'default' => 'open',
                'sensitive' => true,
                'options' => [
                    'open' => ['label' => 'steam::messages.open', 'description' => 'steam::messages.open_help'],
                    'verified' => ['label' => 'fivem::messages.verified', 'description' => 'fivem::messages.verified_help'],
                ],
            ],
        ];
    }

    public function publicProfilesAvailable(): bool
    {
        return $this->usesSteamIdentity() && parent::publicProfilesAvailable();
    }

    public function normalizeUuid(string $uuid): string
    {
        return $this->usesSteamIdentity() ? parent::normalizeUuid($uuid) : self::normalizeCfxId($uuid);
    }

    public static function normalizeCfxId(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^(?:fivem:)?([1-9][0-9]{0,15})$/D', $value, $match)) {
            throw ValidationException::withMessages(['uuid' => __('fivem::messages.invalid_cfx_id')]);
        }

        return 'fivem:'.$match[1];
    }

    public function commandIdentity(string $uuid, ?string $username): array
    {
        if ($this->usesSteamIdentity()) {
            return parent::commandIdentity($uuid, $username);
        }
        if (!str_starts_with($uuid, 'fivem:')) {
            throw ValidationException::withMessages(['uuid' => __('fivem::messages.invalid_cfx_id')]);
        }
        $id = self::normalizeCfxId($uuid);

        return ['uuid' => $id, 'player' => $id, 'cfx_id' => substr($id, 6)];
    }

    public function guestIdentity(string $input): array
    {
        return $this->commandIdentity($this->normalizeUuid($input), null);
    }

    public function assertCanAuthenticate(User $user, ?GameIdentity $identity, Settings $settings): void
    {
        if (
            $settings->get('games.fivem.authentication', 'open') === 'verified' &&
            !$this->verifiedFor($identity, $this->usesSteamIdentity() ? 'steam' : 'cfx')
        ) {
            throw ValidationException::withMessages(['game' => __('fivem::messages.identity_required')]);
        }
    }

    public function validateSettings(User $user, array $settings, Settings $current): void
    {
        if (($settings['authentication'] ?? 'open') !== 'verified') {
            return;
        }
        $provider = $settings['identity_provider'] ?? 'cfx';
        if (
            !app(OAuthRegistry::class)->enabledForAdapter($provider) ||
            !$this->verifiedFor(app(GameRegistry::class)->identityFor($user, $this->id()), $provider)
        ) {
            throw ValidationException::withMessages(['game' => __('fivem::messages.setup_required')]);
        }
    }

    private function verifiedFor(?GameIdentity $identity, string $provider): bool
    {
        return $identity?->verified_at !== null && ($identity->metadata['source'] ?? null) === match ($provider) {
            'steam' => 'steam_openid',
            'cfx' => 'cfx_account',
        };
    }
}
