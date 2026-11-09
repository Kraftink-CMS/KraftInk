<?php

namespace App\Events;

use App\Models\Accounts\User;

class AccountDeleting
{
    public ?string $blockedReason = null;

    public function __construct(public readonly User $user, public readonly string $replacementName)
    {
    }
}
