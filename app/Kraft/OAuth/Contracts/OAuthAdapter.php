<?php

namespace App\Kraft\OAuth\Contracts;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;

interface OAuthAdapter
{
    public function provider(array $configuration): Provider;

    public function emailVerified(User $user): bool;
}
