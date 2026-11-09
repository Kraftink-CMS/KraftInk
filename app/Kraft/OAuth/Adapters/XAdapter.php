<?php

namespace App\Kraft\OAuth\Adapters;

use App\Kraft\OAuth\Contracts\OAuthAdapter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\XProvider;

class XAdapter implements OAuthAdapter
{
    public function provider(array $configuration): Provider
    {
        return Socialite::buildProvider(XProvider::class, $configuration)
            ->enablePKCE()
            ->scopes(['users.read', 'tweet.read', 'users.email']);
    }

    public function emailVerified(User $user): bool
    {
        return filter_var($user->user['confirmed_email'] ?? null, FILTER_VALIDATE_EMAIL) !== false;
    }
}
