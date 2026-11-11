<?php

namespace App\Events;

use App\Models\Accounts\User;

class ApiProfileBuilding
{
    public array $extensions = [];

    public function __construct(public readonly User $user, public readonly array $scopes)
    {
    }
}
