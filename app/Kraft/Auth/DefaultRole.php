<?php

namespace App\Kraft\Auth;

use App\Models\Accounts\Role;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use App\Models\Accounts\User;

class DefaultRole
{
    public function registered(Registered $event): void
    {
        $user = $event->user;
        if (!($user instanceof User) || $user->isServiceAccount()) {
            return;
        }
        DB::transaction(function () use ($user) {
            $role = Role::query()->where('system_key', 'member')->lockForUpdate()->first();
            if ($role && $this->safe($role)) {
                $user->roles()->syncWithoutDetaching([$role->id]);
                $user->unsetRelation('roles');
            }
        }, 3);
    }

    public function safe(Role $role): bool
    {
        return in_array($role->permission_profile ?? 'member', ['member', 'none'], true) &&
            !in_array('*', $role->effectivePermissions(), true);
    }
}
