<?php

namespace App\Kraft\Migration\Adapters\Azuriom;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ContentAccess as DefaultAccess;
use WeakMap;

final class ContentAccess extends DefaultAccess
{
    private ?WeakMap $cache = null;

    public function resolve(array $row, ImportContext $context, string $kind): array
    {
        $review = ['visibility' => 'restricted', 'required_permission' => 'imports.review', 'role_ids' => []];
        if ($kind === 'page') {
            $this->cache ??= new WeakMap();
            if (!isset($this->cache[$context])) {
                $rows = $context->sourceRows('page_role');
                $grants = null;
                if ($rows !== null) {
                    $grants = [];
                    foreach ($rows as $grant) {
                        $grants[(string) $grant['page_id']][] = $grant['role_id'];
                    }
                }
                $this->cache[$context] = ['page_roles' => $grants];
            }
            $grants = $this->cache[$context]['page_roles'];
            if ($grants === null) {
                return $review;
            }
            $sourceRoles = $grants[(string) $row['id']] ?? [];
            $roles = array_values(
                array_unique(
                    array_filter(
                        array_map(fn ($role) => $context->mapped($context->adapter->roleTable(), $role), $sourceRoles),
                    ),
                ),
            );
            if (count($roles) !== count(array_unique($sourceRoles))) {
                return $review;
            }

            return [
                'visibility' => $roles ? 'restricted' : 'public',
                'required_permission' => null,
                'role_ids' => $roles,
            ];
        }

        return parent::resolve($row, $context, $kind);
    }
}
