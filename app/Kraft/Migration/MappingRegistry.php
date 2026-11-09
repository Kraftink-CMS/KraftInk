<?php

namespace App\Kraft\Migration;

use Closure;

final class MappingRegistry
{
    private array $mappings = [];

    private array $settings = [];

    public function settings(string $source, Closure $handler, ?string $plugin = null): void
    {
        $this->settings[$source][$plugin ?? 'core'] = compact('handler', 'plugin');
    }

    public function settingHandlers(string $source): array
    {
        return array_values($this->settings[$source] ?? []);
    }

    public function register(
        string $source,
        string $table,
        Closure $handler,
        int $priority = 100,
        ?string $plugin = null,
        array|Closure $replacements = [],
        string $identity = 'id',
        array $dependencies = [],
        ?Closure $guard = null,
    ): void {
        $this->mappings[$source][$table] = compact(
            'handler',
            'priority',
            'plugin',
            'replacements',
            'identity',
            'dependencies',
            'guard',
        );
    }

    public function mappings(string $source): array
    {
        $mappings = $this->mappings[$source] ?? [];
        uasort($mappings, fn (array $left, array $right) => $left['priority'] <=> $right['priority']);

        return $mappings;
    }
}
