<?php

namespace App\Kraft\OAuth\Adapters;

use App\Kraft\OAuth\Contracts\OAuthAdapter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;

class FacebookAdapter implements OAuthAdapter
{
    public function provider(array $configuration): Provider
    {
        /** @var FacebookProvider $provider */
        $provider = Socialite::buildProvider(FacebookProvider::class, $configuration);

        return $provider->fields(['id', 'name', 'email'])->scopes(['email']);
    }

    public function emailVerified(User $user): bool
    {
        return false;
    }
}
