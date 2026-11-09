<?php

declare(strict_types=1);

class KraftManifest
{
    public static function read(string $directory, ?string $type = null, ?string $id = null): array
    {
        $path = $directory.'/manifest.json';
        if (!is_file($path) || is_link($path) || filesize($path) > 262144) {
            throw new RuntimeException('The package manifest is missing, unsafe or too large.');
        }

        $manifest = self::decode(file_get_contents($path), $type, $id);
        self::marketFiles($manifest, $directory);

        return $manifest;
    }

    public static function decode(string $source, ?string $type = null, ?string $id = null): array
    {
        if (strlen($source) > 262144) {
            throw new RuntimeException('The package manifest is too large.');
        }
        $manifest = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($manifest) ||
            !self::identifier($manifest['id'] ?? null) ||
            !in_array($manifest['type'] ?? null, ['cms', 'plugin', 'theme', 'game'], true) ||
            !is_string($manifest['name'] ?? null) ||
            trim($manifest['name']) === '' ||
            mb_strlen($manifest['name']) > 120 ||
            !is_string($manifest['version'] ?? null) ||
            !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $manifest['version']) ||
            ($type !== null && $manifest['type'] !== $type) ||
            ($id !== null && $manifest['id'] !== $id)
        ) {
            throw new RuntimeException('The package manifest identity is invalid.');
        }
        if (
            $manifest['type'] === 'theme'
                ? !in_array($manifest['target'] ?? null, ['site', 'admin'], true)
                : array_key_exists('target', $manifest)
        ) {
            throw new RuntimeException('The package manifest theme target is invalid.');
        }
        foreach (['requires', 'update'] as $section) {
            if (array_key_exists($section, $manifest) && !is_array($manifest[$section])) {
                throw new RuntimeException('The package manifest section is invalid: '.$section);
            }
        }
        self::market($manifest);
        $requires = $manifest['requires'] ?? [];
        foreach (['cms', 'php'] as $requirement) {
            if (
                !is_string($requires[$requirement] ?? null) ||
                trim($requires[$requirement]) === '' ||
                strlen($requires[$requirement]) > 200
            ) {
                throw new RuntimeException('The package manifest requirements are invalid.');
            }
        }
        foreach (['path', 'valid', 'enabled', 'error', 'cms', 'dependencies'] as $reserved) {
            if (array_key_exists($reserved, $manifest)) {
                throw new RuntimeException('The package manifest contains a reserved field.');
            }
        }
        if (!class_exists(KraftUpdateFiles::class, false)) {
            require_once __DIR__.'/files.php';
        }
        if (array_key_exists('preserve', $manifest['update'] ?? [])) {
            KraftUpdateFiles::preservation($manifest['update']['preserve'], ['manifest.json']);
        }
        if ($manifest['type'] === 'cms') {
            if (array_diff(array_keys($requires), ['cms', 'php'])) {
                throw new RuntimeException('The CMS can only require platform versions.');
            }
            $update = $manifest['update'] ?? [];
            if (
                array_key_exists('repository', $update) &&
                (!is_string($update['repository']) ||
                    !preg_match('~^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$~D', $update['repository']))
            ) {
                throw new RuntimeException('The CMS update repository is invalid.');
            }
            if (
                array_key_exists('public_key', $update) || !is_string($update['signing_key'] ?? null) ||
                trim($update['signing_key']) === '' || strlen($update['signing_key']) > 128
            ) {
                throw new RuntimeException('The CMS update signing key reference is invalid.');
            }
            if (!is_array($manifest['update']['protected'] ?? null)) {
                throw new RuntimeException('The CMS manifest must define permanent protection rules.');
            }
            $rules = KraftUpdateFiles::protectionRules($manifest['update']['protected']);
            if (KraftUpdateFiles::protected('manifest.json', $rules, true)) {
                throw new RuntimeException('Permanent protection covers required package metadata.');
            }
            if (!class_exists(KraftBuiltinPackages::class, false)) {
                require_once __DIR__.'/builtins.php';
            }
            $inventory = KraftBuiltinPackages::inventory($manifest['bundled'] ?? null);
            $required = [
                'manifest.json',
                ...array_map(fn ($scope) => $scope.'/manifest.json', KraftBuiltinPackages::scopes($inventory)),
            ];
            KraftUpdateFiles::preservation($manifest['update']['preserve'] ?? [], $required);
            KraftUpdateFiles::synchronization(array_key_exists('sync', $update) ? $update['sync'] : []);
            foreach ($required as $path) {
                if (KraftUpdateFiles::protected($path, $rules)) {
                    throw new RuntimeException('Permanent protection covers required package metadata.');
                }
            }
        } else {
            if (array_key_exists('protected', $manifest['update'] ?? [])) {
                throw new RuntimeException('Permanent protection is defined by the CMS for every package.');
            }
            if (array_key_exists('sync', $manifest['update'] ?? [])) {
                throw new RuntimeException('Directory synchronization is defined by the CMS for core updates.');
            }
        }

        return $manifest;
    }

    private static function market(array $manifest): void
    {
        if (!array_key_exists('market', $manifest)) {
            return;
        }
        $market = $manifest['market'];
        if (!is_array($market) || ($market !== [] && array_is_list($market))) {
            throw new RuntimeException('The package market presentation is invalid.');
        }
        foreach (['name' => 120, 'description' => 1000, 'image' => 2048] as $field => $maximum) {
            if (!array_key_exists($field, $market)) {
                continue;
            }
            $values = $market[$field];
            if (is_string($values)) {
                $values = [$values];
            } elseif (is_array($values) && $values !== [] && count($values) <= 100) {
                foreach ($values as $locale => $value) {
                    if (
                        !is_string($locale) ||
                        strlen($locale) > 35 ||
                        !preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale)
                    ) {
                        throw new RuntimeException('The package market language is invalid.');
                    }
                }
            } else {
                throw new RuntimeException('The package market presentation is invalid.');
            }
            foreach ($values as $value) {
                if (
                    !is_string($value) ||
                    trim($value) === '' ||
                    !preg_match('//u', $value) ||
                    mb_strlen($value) > $maximum
                ) {
                    throw new RuntimeException('The package market text is invalid.');
                }
                if ($field !== 'image') {
                    continue;
                }
                if (preg_match('/^https:/i', $value)) {
                    $parts = parse_url($value);
                    if (
                        !$parts ||
                        !filter_var($value, FILTER_VALIDATE_URL) ||
                        strtolower($parts['scheme'] ?? '') !== 'https' ||
                        empty($parts['host']) ||
                        isset($parts['user']) ||
                        isset($parts['pass']) ||
                        isset($parts['port']) ||
                        isset($parts['query']) ||
                        isset($parts['fragment']) ||
                        preg_match('/[\x00-\x20\x7f"\'<>`\\\\?#]/', $value)
                    ) {
                        throw new RuntimeException('The package market image URL is invalid.');
                    }
                } elseif (
                    !preg_match(
                        '~^(?:[A-Za-z0-9][A-Za-z0-9._-]*/)*[A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|webp|gif|avif)$~iD',
                        $value,
                    )
                ) {
                    throw new RuntimeException('The package market image path is invalid.');
                }
            }
        }
    }

    private static function marketFiles(array $manifest, string $directory): void
    {
        $images = $manifest['market']['image'] ?? [];
        foreach (is_string($images) ? [$images] : $images as $image) {
            if (preg_match('/^https:/i', $image)) {
                continue;
            }
            $relative = str_starts_with($image, 'resources/market/')
                ? substr($image, strlen('resources/market/'))
                : $image;
            $base = realpath($directory.'/resources/market');
            if (!$base || is_link($directory.'/resources') || is_link($directory.'/resources/market')) {
                throw new RuntimeException('The package market image directory is missing or unsafe.');
            }
            $path = $directory.'/resources/market';
            foreach (explode('/', $relative) as $segment) {
                $path .= '/'.$segment;
                if (is_link($path)) {
                    throw new RuntimeException('The package market image path is unsafe.');
                }
            }
            $resolved = realpath($path);
            if (!$resolved || !is_file($resolved) || !str_starts_with($resolved, $base.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('The package market image file is missing or unsafe.');
            }
        }
    }

    private static function identifier(mixed $id): bool
    {
        return is_string($id) &&
            preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $id) === 1 &&
            !preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])$/D', $id);
    }
}
