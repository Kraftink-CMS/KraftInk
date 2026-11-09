<?php

namespace App\Kraft\Extensions;

use RuntimeException;

class PackagePaths
{
    public function scope(array $extension): string
    {
        $type = $extension['type'] ?? null;
        $id = $extension['id'] ?? null;
        $kind = ['game' => 'games', 'plugin' => 'plugins', 'theme' => 'themes'][$type] ?? null;
        if (!$kind || !is_string($id) || !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $id)) {
            throw new RuntimeException(__('system.extensions.update_boundary'));
        }
        $path = $extension['path'] ?? '';
        $actual = realpath($path);
        $normalize = fn ($value) => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $value ?: ''))
            : str_replace('\\', '/', $value ?: '');
        if (!$actual || !is_dir($actual) || $normalize($actual) !== $normalize($path)) {
            throw new RuntimeException(__('system.extensions.update_boundary'));
        }
        for ($cursor = $path; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) {
                throw new RuntimeException(__('system.extensions.update_boundary'));
            }
        }
        $roots = [
            'extensions/installed/'.$kind => config('kraft.extensions.installed.'.$kind),
            'extensions/bundled/'.$kind => config('kraft.extensions.bundled.'.$kind),
        ];
        foreach ($roots as $relative => $root) {
            $scope = $relative.'/'.$id;
            if (
                $normalize(rtrim($root, '/\\').'/'.$id) === $normalize($actual) &&
                $normalize(realpath(base_path($scope))) === $normalize($actual)
            ) {
                return $scope;
            }
        }

        throw new RuntimeException(__('system.extensions.update_boundary'));
    }
}
