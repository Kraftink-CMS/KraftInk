<?php

namespace App\Events;

use Illuminate\Database\Query\Builder;

class CollectingRoleAssignments
{
    private array $queries = [];

    public function __construct(public readonly array $roleIds)
    {
    }

    public function add(Builder $query): void
    {
        $this->queries[] = $query;
    }

    public function queries(): array
    {
        return $this->queries;
    }
}
