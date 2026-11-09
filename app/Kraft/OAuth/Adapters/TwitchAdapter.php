<?php

namespace App\Kraft\OAuth\Adapters;

use App\Kraft\OAuth\Contracts\OAuthAdapter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\TwitchProvider;

class TwitchAdapter implements OAuthAdapter
{
    public function provider(array $configuration): Provider
    {
        return Socialite::buildProvider(TwitchProvider::class, $configuration)->scopes(['user:read:email']);
    }

    public function emailVerified(User $user): bool
    {
        return false;
    }
}
