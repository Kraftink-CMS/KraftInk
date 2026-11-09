<?php

namespace App\Events;

use App\Models\Accounts\User;

class ExportingPersonalData
{
    public function __construct(public readonly User $user, public array $data = [])
    {
    }
}
