<?php

namespace App\Kraft\OAuth\Contracts;

use App\Models\Accounts\User;
use Laravel\Socialite\Contracts\User as ExternalUser;

interface SynchronizesIdentity
{
    public function identityData(ExternalUser $identity): array;

    public function synchronize(User $user, array $data): void;
}
