<?php

namespace App\Events;

use App\Models\Accounts\User;
use App\Models\Wallet\WalletEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class WalletChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public User $user, public WalletEntry $entry)
    {
    }
}
