<?php

namespace App\Events;

use App\Models\Accounts\User;

class AuthenticatingUser
{
    public function __construct(public User $user, public string $method)
    {
    }
}
