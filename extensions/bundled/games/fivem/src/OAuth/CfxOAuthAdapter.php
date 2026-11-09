<?php

namespace KraftInk\Games\FiveM\OAuth;

use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\Contracts\OAuthAdapter;
use App\Kraft\OAuth\Contracts\SynchronizesIdentity;
use App\Models\Accounts\User;
use KraftInk\Games\FiveM\Adapters\GameAdapter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as ExternalUser;

class CfxOAuthAdapter implements OAuthAdapter, SynchronizesIdentity
{
    public function provider(array $configuration): Provider
    {
        return new CfxProvider($configuration['redirect']);
    }

    public function emailVerified(ExternalUser $user): bool
    {
        return false;
    }

    public function identityData(ExternalUser $identity): array
    {
        return [
            'uuid' => GameAdapter::normalizeCfxId((string) $identity->getId()),
            'username' => $identity->getNickname(),
        ];
    }

    public function synchronize(User $user, array $data): void
    {
        $games = app(GameRegistry::class);
        if ($games->activeId() === 'fivem' && !app(GameAdapter::class)->usesSteamIdentity()) {
            $games->verifyIdentity($user, 'fivem', $data, 'cfx_account');
        }
    }
}
