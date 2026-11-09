<?php

namespace App\Events;

use App\Models\Accounts\User;
use Illuminate\Support\Collection;

class ResolvingUserRoles
{
    public function __construct(public readonly User $user, public Collection $roles)
    {
    }
}
