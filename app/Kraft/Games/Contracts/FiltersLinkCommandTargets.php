<?php

namespace App\Kraft\Games\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface FiltersLinkCommandTargets
{
    public function filterLinkCommandTargets(Builder $query, ?string $capability): void;
}
