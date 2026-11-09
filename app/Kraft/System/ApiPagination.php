<?php

namespace App\Kraft\System;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ApiPagination
{
    public static function perPage(Request $request, int $default = 20): int
    {
        $data = $request->validate([
            'per_page' => 'nullable|integer|between:1,50',
            'page' => 'nullable|integer|min:1',
        ]);

        return (int) ($data['per_page'] ?? $default);
    }

    public static function data(LengthAwarePaginator $page, string $itemsKey = 'items'): array
    {
        return [
            $itemsKey => $page->items(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }
}
