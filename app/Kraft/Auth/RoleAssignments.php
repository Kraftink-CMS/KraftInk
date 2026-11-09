<?php

namespace App\Kraft\Auth;

use App\Models\Accounts\RoleUser;
use App\Events\CollectingRoleAssignments;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RoleAssignments
{
    public function query(array $roleIds): Builder
    {
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), fn ($id) => $id > 0)));
        $base = RoleUser::query()->whereIn('role_id', $roleIds)->select('user_id', 'role_id');
        if ($roleIds === []) {
            return $base->toBase();
        }
        $event = new CollectingRoleAssignments($roleIds);
        event($event);
        foreach ($event->queries() as $index => $query) {
            if ($query->getConnection()->getName() !== $base->getConnection()->getName()) {
                throw new RuntimeException('Role assignment sources must use the account database.');
            }
            $base->union(
                DB::query()
                    ->fromSub($query, 'role_source_'.$index)
                    ->whereIn('role_id', $roleIds)
                    ->select('user_id', 'role_id'),
            );
        }

        return DB::query()->fromSub($base, 'effective_assignments')->select('user_id', 'role_id')->distinct();
    }
}
