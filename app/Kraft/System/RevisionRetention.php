<?php

namespace App\Kraft\System;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RevisionRetention
{
    public function __construct(private RetentionPolicy $policy)
    {
    }

    public function prune(
        Builder|EloquentBuilder $revisions,
        string $parentColumn,
        bool $dryRun = false,
        ?Closure $scope = null,
    ): int {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $parentColumn)) {
            throw new InvalidArgumentException('Invalid revision retention identifier.');
        }
        $cutoff = now()->subDays($this->policy->days('history'));
        $limit = $this->policy->historyLimit();
        $count = 0;
        $lastParent = null;
        do {
            $parents = (clone $revisions)
                ->select($parentColumn)
                ->distinct()
                ->whereNotNull($parentColumn)
                ->when($lastParent !== null, fn ($query) => $query->where($parentColumn, '>', $lastParent))
                ->orderBy($parentColumn)
                ->limit(100)
                ->pluck($parentColumn);
            foreach ($parents as $parent) {
                if ($dryRun) {
                    $count += $this->candidates($revisions, $parentColumn, $parent, $cutoff, $limit, $scope)->count();

                    continue;
                }
                do {
                    $deleted = DB::transaction(function () use (
                        $revisions,
                        $parentColumn,
                        $parent,
                        $cutoff,
                        $limit,
                        $scope,
                    ) {
                        $ids = $this->candidates($revisions, $parentColumn, $parent, $cutoff, $limit, $scope)
                            ->orderBy('id')
                            ->limit(500)
                            ->lockForUpdate()
                            ->pluck('id');

                        return (clone $revisions)->whereIn('id', $ids)->delete();
                    }, 3);
                    $count += $deleted;
                } while ($deleted === 500);
            }
            $lastParent = $parents->last();
        } while ($parents->count() === 100);

        return $count;
    }

    private function candidates(
        Builder|EloquentBuilder $revisions,
        string $parentColumn,
        int|string $parent,
        CarbonInterface $cutoff,
        int $limit,
        ?Closure $scope,
    ): Builder|EloquentBuilder {
        $kept = (clone $revisions)
            ->where($parentColumn, $parent)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');
        $query = (clone $revisions)->where($parentColumn, $parent);
        if ($scope) {
            $query->where($scope);
        }

        return $query->where(
            fn ($candidates) => $candidates->where('created_at', '<', $cutoff)->orWhereNotIn('id', $kept),
        );
    }
}
