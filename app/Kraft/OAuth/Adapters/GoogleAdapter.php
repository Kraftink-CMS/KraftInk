<?php

namespace App\Kraft\OAuth\Adapters;

use App\Kraft\OAuth\Contracts\OAuthAdapter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

class GoogleAdapter implements OAuthAdapter
{
    public function provider(array $configuration): Provider
    {
        return Socialite::buildProvider(GoogleProvider::class, $configuration)
            ->enablePKCE()
            ->scopes(['openid', 'profile', 'email']);
    }

    public function emailVerified(User $user): bool
    {
        return filter_var(
            $user->user['email_verified'] ?? ($user->user['verified_email'] ?? false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
}
