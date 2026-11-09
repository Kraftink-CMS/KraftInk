<?php

namespace App\Kraft\Auth;

use App\Models\Accounts\User;
use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PermissionAccess
{
    public function permissions(?User $user, ?Collection $roles = null): array
    {
        if (!$user) {
            return app(PermissionCatalog::class)->defaults('visitor');
        }
        if ($user->isServiceAccount()) {
            return app(ApiScopes::class)->permissionsForServiceAccount($user);
        }
        $roles ??= $user->effectiveRoles();
        if ($roles->isEmpty()) {
            $member = new Role()
                ->setConnection($user->getConnectionName())
                ->newQuery()
                ->where('system_key', 'member')
                ->first();
            if ($member && app(DefaultRole::class)->safe($member)) {
                return $member->effectivePermissions();
            }

            return app(PermissionCatalog::class)->defaults('member');
        }

        return $roles->flatMap(fn ($role) => $role->effectivePermissions())->unique()->values()->all();
    }

    public function allows(?User $user, string $permission): bool
    {
        $permissions = $this->permissions($user);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function filterQuery(
        Builder $query,
        string $column,
        array $permissions,
        ?string $requiredPermission = null,
        bool $allowEmpty = true,
    ): Builder {
        if (in_array('*', $permissions, true)) {
            return $query;
        }
        if ($requiredPermission !== null && !in_array($requiredPermission, $permissions, true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(
            fn (Builder $access) => $access
                ->whereNull($column)
                ->when($allowEmpty, fn (Builder $access) => $access->orWhere($column, ''))
                ->orWhereIn($column, $permissions),
        );
    }
}
