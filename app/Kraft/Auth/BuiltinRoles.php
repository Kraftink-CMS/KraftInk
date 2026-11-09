<?php

namespace App\Kraft\Auth;

use App\Models\Accounts\Role;
use Illuminate\Support\Facades\DB;

class BuiltinRoles
{
    public const array DEFAULT_COLORS = [
        'owner' => '#c04f1c',
        'admin' => '#6f547f',
        'mod' => '#3f7468',
        'member' => '#746960',
    ];

    public static function create(): array
    {
        return DB::transaction(function () {
            $roles = [];
            foreach (['owner' => 999, 'admin' => 800, 'mod' => 500, 'member' => 0] as $key => $position) {
                $role = Role::query()->where('system_key', $key)->first();
                if (!$role) {
                    $slug = $key;
                    for ($suffix = 2; Role::query()->where('slug', $slug)->exists(); $suffix++) {
                        $slug = $key.'-'.$suffix;
                    }
                    $role = Role::query()->create([
                        'name' => __('admin.members.profile_'.$key),
                        'slug' => $slug,
                        'system_key' => $key,
                        'permission_profile' => $key === 'owner' ? 'none' : $key,
                        'permissions' => $key === 'owner' ? ['*'] : [],
                        'permission_overrides' => [],
                        'position' => $position,
                        'color' => self::DEFAULT_COLORS[$key],
                    ]);
                }
                $roles[$key] = $role;
            }

            return $roles;
        }, 3);
    }
}
