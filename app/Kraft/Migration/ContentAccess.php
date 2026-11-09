<?php

namespace App\Kraft\Migration;

class ContentAccess
{
    public function resolve(array $row, ImportContext $context, string $kind): array
    {
        $review = ['visibility' => 'restricted', 'required_permission' => 'imports.review', 'role_ids' => []];
        if (preg_match('/\{%|\{isConnected}|\{isServerOnline}/', (string) ($row['content'] ?? ''))) {
            return $review;
        }
        if (
            array_any(['roles', 'ranks', 'permissions', 'permission', 'restricted'], fn ($field) => !empty($row[$field]))
        ) {
            return $review;
        }
        if (isset($row['visibility']) && !in_array($row['visibility'], ['public', 'all', 0, '0'], true)) {
            return $review;
        }

        return ['visibility' => 'public', 'required_permission' => null, 'role_ids' => []];
    }
}
