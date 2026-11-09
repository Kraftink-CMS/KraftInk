<?php

namespace App\Events;

use App\Models\Accounts\User;

class OAuthAccountUnlinked
{
    public function __construct(public User $user, public string $provider)
    {
    }
}
