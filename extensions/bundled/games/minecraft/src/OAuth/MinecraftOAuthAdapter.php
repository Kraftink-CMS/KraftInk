<?php

namespace KraftInk\Games\Minecraft\OAuth;

use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\Contracts\OAuthAdapter;
use App\Kraft\OAuth\Contracts\SynchronizesIdentity;
use App\Models\Accounts\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as ExternalUser;
use Laravel\Socialite\Facades\Socialite;
use KraftInk\Games\Minecraft\Adapters\MinecraftAdapter;

class MinecraftOAuthAdapter implements OAuthAdapter, SynchronizesIdentity
{
    public function provider(array $configuration): Provider
    {
        return Socialite::buildProvider(StatefulMinecraftProvider::class, $configuration)
            ->enablePKCE()
            ->scopes(['XboxLive.signin']);
    }

    public function emailVerified(ExternalUser $user): bool
    {
        return false;
    }

    public function identityData(ExternalUser $identity): array
    {
        return [
            'uuid' => app(MinecraftAdapter::class)->normalizeUuid($identity->getId()),
            'username' => $identity->getName(),
        ];
    }

    public function synchronize(User $user, array $data): void
    {
        app(GameRegistry::class)->verifyIdentity($user, 'minecraft', $data, 'microsoft_minecraft_oauth');
    }
}
