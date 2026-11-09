<?php

namespace App\Kraft\OAuth\Adapters;

use App\Kraft\OAuth\Contracts\OAuthAdapter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class MicrosoftAdapter implements OAuthAdapter
{
    public function provider(array $configuration): Provider
    {
        /** @var MicrosoftProvider $provider */
        $provider = Socialite::buildProvider(MicrosoftProvider::class, $configuration);

        return $provider
            ->setConfig(
                new Config($configuration['client_id'], $configuration['client_secret'], $configuration['redirect'], [
                    'tenant' => $configuration['tenant'] ?? 'common',
                ]),
            )
            ->enablePKCE()
            ->scopes(['openid', 'profile', 'email', 'User.Read']);
    }

    public function emailVerified(User $user): bool
    {
        return false;
    }
}
