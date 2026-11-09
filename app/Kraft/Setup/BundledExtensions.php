<?php

namespace App\Kraft\Setup;

use App\Kraft\Extensions\DependencyConstraints;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\ExtensionRequirements;
use App\Kraft\Extensions\Manifest;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;
use RuntimeException;
use Random\RandomException;

class BundledExtensions
{
    public function __construct(private ExtensionManager $extensions, private Manifest $manifest)
    {
    }

    public function catalogue(): array
    {
        $items = [];
        foreach ($this->extensions->all() as $extension) {
            if (
                !in_array($extension['type'], ['plugin', 'theme'], true) ||
                ($extension['type'] === 'plugin' && !empty($extension['builtin'])) ||
                ($extension['type'] === 'theme' && $extension['valid'] && $extension['target'] !== 'site')
            ) {
                continue;
            }
            $error = $extension['valid'] ? null : __('system.extensions.invalid_manifest');
            if ($error === null) {
                try {
                    $this->manifest->compatible($extension);
                } catch (Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
            $items[$extension['id']] = [
                'id' => $extension['id'],
                'name' => $extension['name'],
                'type' => $extension['type'],
                'version' => $extension['version'],
                'builtin' => (bool) ($extension['builtin'] ?? false),
                'error' => $error,
                'dependencies' => $extension['valid']
                    ? app(DependencyConstraints::class)->dependencies($extension)
                    : [],
            ];
        }
        uasort($items, fn ($left, $right) => strnatcasecmp($left['name'], $right['name']));

        return $items;
    }

    public function plan(array $plugins, ?string $theme, ?string $game): array
    {
        $all = $this->extensions->all();
        if (
            $theme !== null &&
            (($all[$theme]['type'] ?? null) !== 'theme' || !$all[$theme]['valid'] || $all[$theme]['target'] !== 'site')
        ) {
            throw ValidationException::withMessages([
                'extensions' => __('system.install.extensions.unavailable', ['id' => $theme]),
            ]);
        }
        $selected = array_values(array_unique(array_filter([...$plugins, $theme])));
        $ordered = [];
        $visiting = [];
        $visit = function (string $id) use (&$visit, &$ordered, &$visiting, $all, $theme, $game): void {
            if (isset($ordered[$id])) {
                return;
            }
            $extension = $all[$id] ?? null;
            if (
                !$extension ||
                !$extension['valid'] ||
                !in_array($extension['type'], ['plugin', 'theme'], true) ||
                ($extension['type'] === 'theme' && $id !== $theme)
            ) {
                throw ValidationException::withMessages([
                    'extensions' => __('system.install.extensions.unavailable', ['id' => $id]),
                ]);
            }
            if (isset($visiting[$id])) {
                throw ValidationException::withMessages([
                    'extensions' => __('system.extensions.dependency_cycle', ['id' => $id]),
                ]);
            }
            try {
                $this->manifest->compatible($extension);
            } catch (Throwable $exception) {
                throw ValidationException::withMessages(['extensions' => $exception->getMessage()]);
            }
            $visiting[$id] = true;
            $constraints = app(DependencyConstraints::class);
            foreach ($constraints->extensions($constraints->requirements($extension)) as $dependency => $requirement) {
                $constraint = $requirement['constraint'];
                $target = $all[$dependency] ?? null;
                if (
                    !$target ||
                    !$target['valid'] ||
                    $target['type'] !== $requirement['type'] ||
                    !Semver::satisfies($target['version'], $constraint)
                ) {
                    throw ValidationException::withMessages([
                        'extensions' => __('system.extensions.install_dependency', [
                            'id' => $dependency,
                            'constraint' => $constraint,
                        ]),
                    ]);
                }
                if ($target['type'] === 'game') {
                    if ($dependency !== $game || !($target['builtin'] ?? false)) {
                        throw ValidationException::withMessages([
                            'extensions' => __('system.extensions.install_dependency', [
                                'id' => $dependency,
                                'constraint' => $constraint,
                            ]),
                        ]);
                    }
                } else {
                    $visit($dependency);
                }
            }
            unset($visiting[$id]);
            $ordered[$id] = [
                'id' => $id,
                'name' => $extension['name'],
                'type' => $extension['type'],
                'version' => $extension['version'],
                'builtin' => (bool) ($extension['builtin'] ?? false),
            ];
        };
        foreach ($plugins as $id) {
            if (($all[$id]['type'] ?? null) !== 'plugin' || !empty($all[$id]['builtin'])) {
                throw ValidationException::withMessages([
                    'extensions' => __('system.install.extensions.unavailable', ['id' => $id]),
                ]);
            }
        }
        foreach ($selected as $id) {
            $visit($id);
        }
        $requirements = app(ExtensionRequirements::class);
        $selectedPackages = [];
        if ($game !== null && ($all[$game]['type'] ?? null) === 'game' && $all[$game]['valid']) {
            $selectedPackages[] = $all[$game];
        }
        foreach (array_keys($ordered) as $id) {
            $extension = $all[$id];
            foreach ($selectedPackages as $other) {
                if ($requirements->conflictsWith($extension, $other)) {
                    throw ValidationException::withMessages([
                        'extensions' => __('system.extensions.conflict', ['id' => $requirements->name($other)]),
                    ]);
                }
            }
            $selectedPackages[] = $extension;
        }

        return array_values($ordered);
    }

    public function install(array $plan): array
    {
        $failed = [];
        $available = $this->extensions->all();
        foreach ($plan as $extension) {
            $current = $available[$extension['id']] ?? [];
            if (empty($current['builtin'])) {
                continue;
            }
            try {
                if (empty($current['valid']) || empty($current['installed']) || empty($current['manifest_current'])) {
                    throw new RuntimeException(__('system.extensions.incomplete_installation'));
                }
                if ($extension['type'] === 'theme') {
                    if ($this->extensions->selectedTheme() !== $extension['id']) {
                        $this->extensions->selectTheme($extension['id']);
                    }
                } elseif (empty($current['enabled'])) {
                    $this->extensions->toggle($extension['id'], true);
                }
            } catch (Throwable $exception) {
                $failed[] = $extension['name'];
                Log::warning('Builtin installation choice failed', [
                    'extension' => $extension['id'],
                    'exception' => $exception::class,
                ]);
            }
        }
        foreach ($plan as $extension) {
            $current = $available[$extension['id']] ?? [];
            if (!empty($current['builtin'])) {
                continue;
            }
            if (
                !empty($current['valid']) &&
                !empty($current['installed']) &&
                !empty($current['manifest_current']) &&
                !empty($current['enabled']) &&
                ($extension['type'] !== 'theme' || $this->extensions->selectedTheme() === $extension['id'])
            ) {
                continue;
            }
            try {
                $this->extensions->install($extension['id'], [
                    'enable_after_install' => true,
                    'select_theme' => $extension['type'] === 'theme',
                ]);
            } catch (Throwable $exception) {
                $failed[] = $extension['name'];
                Log::warning('Optional installation package failed', [
                    'extension' => $extension['id'],
                    'exception' => $exception::class,
                ]);
            }
        }

        return $failed;
    }

    /**
     * @throws RandomException
     */
    public function reserveProgress(array $plan): void
    {
        foreach ($plan as $extension) {
            if (!empty($extension['builtin'])) {
                continue;
            }
            session()->put('updates.installation_tokens.'.$extension['id'], bin2hex(random_bytes(32)));
        }
    }

    public function incomplete(array $plan): array
    {
        $available = $this->extensions->all();
        $failed = [];
        foreach ($plan as $extension) {
            $current = $available[$extension['id']] ?? [];
            if (
                empty($current['valid']) ||
                empty($current['installed']) ||
                empty($current['manifest_current']) ||
                empty($current['enabled']) ||
                ($extension['type'] === 'theme' && $this->extensions->selectedTheme() !== $extension['id'])
            ) {
                $failed[] = $extension['name'];
            }
        }

        return $failed;
    }
}
