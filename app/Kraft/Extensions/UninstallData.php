<?php

namespace App\Kraft\Extensions;

use RuntimeException;

class UninstallData
{
    public static function validate(array $manifest): array
    {
        $data = $manifest['uninstall'] ?? [];
        if (!is_array($data) || array_diff(array_keys($data), ['settings', 'storage', 'notices'])) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        $id = $manifest['id'];
        foreach (['settings', 'storage', 'notices'] as $kind) {
            if (isset($data[$kind]) && (!is_array($data[$kind]) || !array_is_list($data[$kind]))) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            foreach ($data[$kind] ?? [] as $value) {
                if (!is_string($value)) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
                $valid = match ($kind) {
                    'settings' => preg_match('/^[a-z][a-z0-9_.-]*\.$/D', $value) &&
                        collect([$id.'.', 'secret.'.$id.'.', 'theme.'.$id.'.', 'secret.theme.'.$id.'.'])
                            ->contains(fn ($prefix) => str_starts_with($value, $prefix)),
                    'storage' => preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $value) &&
                        (str_starts_with($value, $id.'/') || $value === 'editor-images/'.$id),
                    'notices' => preg_match('/^'.preg_quote($id, '/').'__[a-z][a-z0-9_]*$/D', $value),
                };
                if (!$valid) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
            }
        }

        return array_replace(['settings' => [], 'storage' => [], 'notices' => []], $data);
    }
}
