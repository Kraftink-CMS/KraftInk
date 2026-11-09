<?php

namespace KraftInk\Integrations\Steam\OAuth;

use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\Contracts\OAuthAdapter;
use App\Kraft\OAuth\Contracts\SynchronizesIdentity;
use App\Models\Accounts\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as ExternalUser;
use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;
use KraftInk\Integrations\Steam\Services\Profiles\SteamIdentity;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SteamOAuthAdapter implements OAuthAdapter, SynchronizesIdentity
{
    public function provider(array $configuration): Provider
    {
        return new SteamOpenId($configuration);
    }

    public function emailVerified(ExternalUser $user): bool
    {
        return false;
    }

    public function identityData(ExternalUser $identity): array
    {
        $games = app(GameRegistry::class);
        $id = $games->activeId();
        $adapter = $games->find($id);

        return [
            'game' => $adapter instanceof SteamGameAdapter && $adapter->usesSteamIdentity() ? $id : null,
            'uuid' => SteamIdentity::normalize((string) $identity->getId()),
            'username' => mb_substr((string) $identity->getNickname(), 0, 60),
        ];
    }

    public function synchronize(User $user, array $data): void
    {
        $game = $data['game'] ?? null;
        if (!$game) {
            return;
        }
        $registry = app(GameRegistry::class);
        $adapter = $registry->find($game);
        if ($registry->activeId() !== $game || !$adapter instanceof SteamGameAdapter || !$adapter->usesSteamIdentity()) {
            throw new HttpException(422);
        }
        $registry->verifyIdentity($user, $game, $data, 'steam_openid');
    }
}
