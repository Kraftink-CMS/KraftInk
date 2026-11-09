<?php

namespace App\Kraft\OAuth\Adapters;

use App\Kraft\OAuth\Contracts\OAuthAdapter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Manager\Config;

class DiscordAdapter implements OAuthAdapter
{
    public function provider(array $configuration): Provider
    {
        /** @var DiscordProvider $provider */
        $provider = Socialite::buildProvider(DiscordProvider::class, $configuration);

        return $provider
            ->setConfig(
                new Config($configuration['client_id'], $configuration['client_secret'], $configuration['redirect']),
            )
            ->enablePKCE()
            ->withConsent()
            ->scopes(['identify', 'email']);
    }

    public function emailVerified(User $user): bool
    {
        return ($user->user['verified'] ?? false) === true;
    }
}
