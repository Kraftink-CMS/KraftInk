<?php

namespace App\Kraft\System;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ListingPagination
{
    public function paginate(
        iterable $items,
        Request $request,
        string $pageName = 'page',
        int $perPage = 25,
    ): LengthAwarePaginator {
        $items = collect($items)->values();
        $page = min(max(1, (int) ceil($items->count() / $perPage)), max(1, $request->integer($pageName, 1)));

        return new LengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page, [
            'path' => $request->url(),
            'pageName' => $pageName,
            'query' => $request->query(),
        ]);
    }
}
