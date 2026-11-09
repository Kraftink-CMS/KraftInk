<?php

namespace App\Kraft\System;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class TableQuery
{
    public static function paginate(
        mixed $query,
        array $sorts = [],
        int $perPage = 10,
        string $pageName = 'page',
        array $search = [],
    ): LengthAwarePaginator {
        $request = request();
        $perPage = max(1, min(100, $perPage));
        $sizes = array_values(array_unique([10, 25, 50, 100, $perPage]));
        sort($sizes);
        $size = filter_var($request->query($pageName.'_per_page'), FILTER_VALIDATE_INT);
        $size = in_array($size, $sizes, true) ? $size : $perPage;
        $columns = [];
        foreach ($sorts as $key => $column) {
            $columns[is_int($key) ? $column : $key] = $column;
        }
        $sort = $request->query($pageName.'_sort', '');
        $sort = is_string($sort) && isset($columns[$sort]) ? $sort : '';
        $direction = $request->query($pageName.'_direction') === 'desc' ? 'desc' : 'asc';
        $term = $request->query($pageName.'_search', '');
        $term = $search && is_string($term) ? mb_substr(trim($term), 0, 100) : '';
        $page = filter_var($request->query($pageName, 1), FILTER_VALIDATE_INT);
        $page = max(1, min(1000000, $page ?: 1));
        $request->attributes->set('kraft.tables.'.$pageName, [
            'sizes' => $sizes,
            'sorts' => array_keys($columns),
            'sort' => $sort,
            'direction' => $direction,
            'searchable' => count($search) > 0,
            'search' => $term,
        ]);

        if ($query instanceof Relation) {
            $query = clone $query->getQuery();
        } elseif ($query instanceof EloquentBuilder || $query instanceof Builder) {
            $query = clone $query;
        } else {
            $items = collect($query)->values();
            if ($term !== '') {
                $needle = mb_strtolower($term);
                $items = $items->filter(
                    fn ($item) => collect($search)->contains(
                        fn ($column) => str_contains(mb_strtolower((string) data_get($item, $column)), $needle),
                    ),
                );
            }
            if ($sort !== '') {
                $items = $items->sortBy([[$columns[$sort], $direction]]);
            }
            $page = min($page, max(1, (int) ceil($items->count() / $size)));

            return new LengthAwarePaginator($items->forPage($page, $size)->values(), $items->count(), $size, $page, [
                'path' => $request->url(),
                'pageName' => $pageName,
                'query' => $request->query(),
            ]);
        }

        if ($term !== '') {
            self::whereContains($query, $search, $term);
        }
        if ($sort !== '') {
            $query->reorder()->orderBy($columns[$sort], $direction);
            if ($query instanceof EloquentBuilder) {
                $from = $query->getQuery()->from;
                $alias =
                    is_string($from) && preg_match('/\s+(?:as\s+)?([a-zA-Z_][a-zA-Z0-9_]*)$/i', $from, $matches)
                        ? $matches[1]
                        : $query->getModel()->getTable();
                $key = $alias.'.'.$query->getModel()->getKeyName();
                if ($columns[$sort] !== $key) {
                    $query->orderBy($key, $direction);
                }
            }
        }
        $total =
            $query instanceof EloquentBuilder
                ? $query->toBase()->getCountForPagination()
                : $query->getCountForPagination();
        $page = min($page, max(1, (int) ceil($total / $size)));

        return $query->paginate($size, ['*'], $pageName, $page, $total)->withQueryString();
    }

    public static function whereContains(EloquentBuilder|Builder $query, array $columns, string $term): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $connection = $query->getConnection();
        $grammar = $connection->getQueryGrammar();
        $postgres = $connection->getDriverName() === 'pgsql';
        $query->where(function ($nested) use ($columns, $pattern, $grammar, $postgres) {
            foreach ($columns as $column) {
                $column = $grammar->wrap($column);
                $predicate = $postgres ? $column.'::text ilike' : $column.' like';
                $nested->orWhereRaw($predicate." ? escape '!'", [$pattern]);
            }
        });
    }

    public static function options(LengthAwarePaginator $paginator): array
    {
        return request()->attributes->get('kraft.tables.'.$paginator->getPageName(), [
            'sizes' => [$paginator->perPage()],
            'sorts' => [],
            'sort' => '',
            'direction' => 'asc',
            'searchable' => false,
            'search' => '',
        ]);
    }

    public static function fields(array $query, array $except = [], string $prefix = ''): array
    {
        $fields = [];
        foreach ($query as $key => $value) {
            if ($prefix === '' && in_array($key, $except, true)) {
                continue;
            }
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
            if (is_array($value)) {
                array_push($fields, ...self::fields($value, [], $name));
            } elseif (is_scalar($value)) {
                $fields[] = ['name' => $name, 'value' => (string) $value];
            }
        }

        return $fields;
    }
}
