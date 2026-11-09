<?php

namespace App\Events;

use App\Models\Accounts\User;
use Closure;

class CollectingLinkDeliverySources
{
    public array $sources = [];

    public function __construct(public readonly User $user)
    {
    }

    public function add(
        string $id,
        string $label,
        string $permission,
        Closure $query,
        Closure $details,
        array $statuses = [],
        ?Closure $actionable = null,
    ): void {
        if ($this->user->hasPermission($permission)) {
            $this->sources[$id] = compact('label', 'permission', 'query', 'details', 'statuses', 'actionable');
        }
    }
}
