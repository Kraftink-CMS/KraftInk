<?php

namespace App\Kraft\Extensions;

use App\Kraft\Games\GameRegistry;
use Composer\Semver\Semver;

class ExtensionRequirements
{
    public function name(array $extension): string
    {
        $name = __($extension['name'] ?? $extension['id']);

        return is_string($name) ? $name : $extension['id'];
    }

    public function errors(array $extension, array $all, bool $activation): array
    {
        $errors = app(Manifest::class)->compatibilityErrors($extension);
        $game = $extension['type'] === 'plugin' ? app(GameRegistry::class)->activeId() : null;
        if (!GameSupport::matches($extension, $game)) {
            $errors[] = __('system.extensions.game_incompatible');
        }
        $constraints = app(DependencyConstraints::class);
        foreach ($constraints->extensions($constraints->requirements($extension)) as $id => $dependency) {
            $constraint = $dependency['constraint'];
            $target = $all[$id] ?? null;
            if (
                !$target ||
                !($target['valid'] ?? false) ||
                $target['type'] !== $dependency['type'] ||
                ($activation
                    ? empty($target['enabled'])
                    : empty($target['installed']) || empty($target['manifest_current'])) ||
                !Semver::satisfies($activation ? $target['version'] : $target['installed_version'], $constraint)
            ) {
                $errors[] = __(
                    $activation ? 'system.extensions.dependency_missing' : 'system.extensions.install_dependency',
                    [
                        'id' => $target ? $this->name($target) : $id,
                        'constraint' => $constraint,
                    ],
                );
            }
        }
        foreach ($all as $other) {
            if (!empty($other['enabled']) && !empty($other['valid']) && $this->conflictsWith($extension, $other)) {
                $errors[] = __('system.extensions.conflict', ['id' => $this->name($other)]);
            }
        }

        return array_values(array_unique($errors));
    }

    public function conflictsWith(array $extension, array $other): bool
    {
        if ($extension['id'] === $other['id']) {
            return false;
        }
        $forward = $extension['conflicts'][$other['type'].':'.$other['id']] ?? null;
        $reverse = $other['conflicts'][$extension['type'].':'.$extension['id']] ?? null;

        return ($forward !== null && Semver::satisfies($other['version'], $forward)) ||
            ($reverse !== null && Semver::satisfies($extension['version'], $reverse));
    }

    public function dependents(string $id, array $all, bool $installed = false): array
    {
        $constraints = app(DependencyConstraints::class);
        $names = [];
        foreach ($all as $other) {
            if (
                $other['id'] !== $id &&
                !empty($other['valid']) &&
                !empty($other[$installed ? 'installed' : 'enabled']) &&
                array_key_exists($id, $constraints->dependencies($other))
            ) {
                $names[] = $this->name($other);
            }
        }
        $names = array_values(array_unique($names));
        usort($names, 'strnatcasecmp');

        return $names;
    }

    public function dependentErrors(string $id, array $all, bool $installed = false): array
    {
        $names = $this->dependents($id, $all, $installed);

        return $names
            ? [
                __($installed ? 'system.extensions.required_installed_by' : 'system.extensions.required_active_by', [
                    'extensions' => implode(', ', $names),
                ]),
            ]
            : [];
    }

    public function cycles(array $all): array
    {
        $constraints = app(DependencyConstraints::class);
        $index = 0;
        $indices = [];
        $low = [];
        $stack = [];
        $onStack = [];
        $errors = [];
        $visit = function (string $id) use (
            &$visit,
            &$index,
            &$indices,
            &$low,
            &$stack,
            &$onStack,
            &$errors,
            $constraints,
            $all,
        ): void {
            $indices[$id] = $low[$id] = $index++;
            $stack[] = $id;
            $onStack[$id] = true;
            foreach ($constraints->dependencies($all[$id]) as $dependency => $constraint) {
                if (empty($all[$dependency]['enabled']) || empty($all[$dependency]['valid'])) {
                    continue;
                }
                if (!array_key_exists($dependency, $indices)) {
                    $visit($dependency);
                    $low[$id] = min($low[$id], $low[$dependency]);
                } elseif (!empty($onStack[$dependency])) {
                    $low[$id] = min($low[$id], $indices[$dependency]);
                }
            }
            if ($low[$id] !== $indices[$id]) {
                return;
            }
            $members = [];
            do {
                $member = array_pop($stack);
                unset($onStack[$member]);
                $members[] = $member;
            } while ($member !== $id);
            if (count($members) > 1 || array_key_exists($id, $constraints->dependencies($all[$id]))) {
                $names = array_map(fn ($member) => $this->name($all[$member]), $members);
                usort($names, 'strnatcasecmp');
                $errors[] = __('system.extensions.dependency_cycle_members', ['extensions' => implode(', ', $names)]);
            }
        };
        foreach ($all as $id => $extension) {
            if (!empty($extension['enabled']) && !empty($extension['valid']) && !array_key_exists($id, $indices)) {
                $visit($id);
            }
        }

        return array_values(array_unique($errors));
    }
}
