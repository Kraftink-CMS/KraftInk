<?php

declare(strict_types=1);

class KraftBuiltinPackages
{
    public static function inventory(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['game', 'plugin', 'theme'])) {
            throw new RuntimeException('The builtin package inventory is invalid.');
        }
        $inventory = [];
        $seen = [];
        foreach (['game', 'plugin', 'theme'] as $type) {
            $ids = $value[$type] ?? [];
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 1000) {
                throw new RuntimeException('The builtin package inventory is invalid.');
            }
            foreach ($ids as $id) {
                if (
                    !is_string($id) ||
                    !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $id) ||
                    preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])$/D', $id) ||
                    isset($seen[$id])
                ) {
                    throw new RuntimeException('The builtin package inventory has an invalid or duplicate identifier.');
                }
                $seen[$id] = true;
            }
            $inventory[$type] = $ids;
        }

        return $inventory;
    }

    /** @return list<string> */
    public static function scopes(array $inventory): array
    {
        $scopes = [];
        foreach (self::inventory($inventory) as $type => $ids) {
            $prefix = match ($type) {
                'game' => 'extensions/bundled/games/',
                'plugin' => 'extensions/bundled/plugins/',
                'theme' => 'extensions/bundled/themes/',
            };
            foreach ($ids as $id) {
                $scopes[] = $prefix.$id;
            }
        }

        return $scopes;
    }

    public static function scope(string $path, array $inventory): ?string
    {
        return array_find(
            self::scopes($inventory),
            fn (string $scope): bool => $path === $scope || str_starts_with($path, $scope.'/'),
        );
    }

    public static function preserved(string $relative, array $preserve = []): bool
    {
        if (!class_exists(KraftUpdateFiles::class, false)) {
            require_once __DIR__.'/files.php';
        }

        return KraftUpdateFiles::preserved(
            $relative,
            true,
            KraftUpdateFiles::preservation($preserve, ['manifest.json']),
        );
    }

    /** @throws JsonException */
    public static function manifests(string $root, array $inventory): array
    {
        $root = realpath($root);
        if (!$root || !is_dir($root)) {
            throw new RuntimeException('The builtin package root is missing.');
        }
        $manifests = [];
        foreach (self::inventory($inventory) as $type => $ids) {
            foreach ($ids as $id) {
                $scope =
                    match ($type) {
                        'game' => 'extensions/bundled/games/',
                        'plugin' => 'extensions/bundled/plugins/',
                        'theme' => 'extensions/bundled/themes/',
                    }.$id;
                $directory = $root;
                foreach (explode('/', $scope) as $part) {
                    $directory .= DIRECTORY_SEPARATOR.$part;
                    if (is_link($directory)) {
                        throw new RuntimeException('Builtin package paths must not contain symbolic links.');
                    }
                }
                if (!class_exists(KraftManifest::class, false)) {
                    require_once __DIR__.'/manifest.php';
                }
                $manifest = KraftManifest::read($directory, $type, $id);
                $manifest['path'] = $directory;
                $manifest['scope'] = $scope;
                $manifests[$id] = $manifest;
            }
        }

        self::selfContained($manifests);

        return $manifests;
    }

    public static function selfContained(array $manifests): void
    {
        foreach ($manifests as $manifest) {
            $requires = $manifest['requires'] ?? null;
            if (
                !is_array($requires) ||
                !isset($requires['cms'], $requires['php']) ||
                array_key_exists('cms', $manifest) ||
                array_key_exists('dependencies', $manifest)
            ) {
                throw new RuntimeException('The bundled extension dependencies are invalid.');
            }
            $extensions = [];
            foreach ($requires as $reference => $constraint) {
                if (
                    !is_string($reference) ||
                    !is_string($constraint) ||
                    trim($constraint) === '' ||
                    strlen($constraint) > 200
                ) {
                    throw new RuntimeException('The bundled extension dependencies are invalid.');
                }
                if (in_array($reference, ['cms', 'php'], true)) {
                    continue;
                }
                if (
                    !preg_match('/^(plugin|theme|game):([a-z][a-z0-9-]{1,49})$/D', $reference, $matches) ||
                    isset($extensions[$matches[2]])
                ) {
                    throw new RuntimeException('The bundled extension dependencies are invalid.');
                }
                $id = $matches[2];
                $extensions[$id] = true;
                if (!isset($manifests[$id])) {
                    throw new RuntimeException(
                        'A bundled extension requires a package outside its declared builtin inventory.',
                    );
                }
                if (($manifests[$id]['type'] ?? null) !== $matches[1]) {
                    throw new RuntimeException(
                        'A bundled extension dependency has a different type in its declared builtin inventory.',
                    );
                }
            }
        }
    }
}
