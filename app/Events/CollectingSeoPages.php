<?php

namespace App\Events;

class CollectingSeoPages
{
    private array $pages = [];

    public function add(
        string $route,
        string $label,
        array $variables = [],
        array $aliases = [],
        array $defaults = [],
        array $excerptVariables = [],
    ): void {
        $this->pages[$route] = compact('route', 'label', 'variables', 'aliases', 'defaults', 'excerptVariables');
    }

    public function pages(): array
    {
        return $this->pages;
    }
}
