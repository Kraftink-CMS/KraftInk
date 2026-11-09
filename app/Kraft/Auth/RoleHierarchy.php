<?php

namespace App\Kraft\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Validation\ValidationException;

class RoleHierarchy
{
    public function canManage(User $actor, User $target): bool
    {
        return $actor->hasPermission('*') ||
            ($actor->id !== $target->id &&
                $this->position($actor) > $this->position($target) &&
                !$target->hasPermission('*'));
    }

    public function assertUser(User $actor, User $target): void
    {
        if (!$this->canManage($actor, $target)) {
            throw new HttpException(403, __('admin.members.hierarchy_denied'));
        }
        foreach ($target->effectiveRoles() as $role) {
            $this->assertRole($actor, $role);
        }
    }

    public function assertRole(User $actor, Role $role): void
    {
        if (!$this->canManageRole($actor, $role)) {
            throw new HttpException(403, __('admin.members.hierarchy_denied'));
        }
    }

    public function canManageRole(User $actor, Role $role): bool
    {
        return $actor->hasPermission('*') ||
            ($role->position < $this->position($actor) &&
                collect($role->effectivePermissions())->every(fn ($permission) => $actor->hasPermission($permission)));
    }

    public function position(User $user): int
    {
        return (int) ($user->effectiveRoles()->max('position') ?? -1);
    }

    public function assertOtherOwner(User $target): void
    {
        if (!$target->hasPermission('*')) {
            return;
        }
        $exists = User::members()
            ->whereKeyNot($target->id)
            ->whereNull('anonymized_at')
            ->where('import_locked', false)
            ->withoutActiveBan()
            ->whereHas('roles', fn ($query) => $query->whereJsonContains('permissions', '*'))
            ->exists();
        if (!$exists) {
            throw ValidationException::withMessages(['roles' => __('admin.messages.last_owner')]);
        }
    }
}
