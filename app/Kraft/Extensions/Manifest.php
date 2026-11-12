<?php

namespace App\Kraft\Extensions;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use App\Kraft\Auth\PermissionCatalog;
use RuntimeException;
use KraftManifest;
use Throwable;
use App\Kraft\Appearance;
use App\Kraft\ContentIcons;
use Composer\Autoload\ClassLoader;
use JsonException;
use stdClass;

class Manifest
{
    private static ?array $protectedNamespaces = null;

    public function read(string $directory, ?string $expectedType = null, ?string $expectedId = null): array
    {
        if (!class_exists(KraftManifest::class, false)) {
            require_once dirname(__DIR__, 3).'/updater/manifest.php';
        }
        try {
            $manifest = KraftManifest::read($directory);
        } catch (Throwable $error) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'), previous: $error);
        }
        if (!in_array($manifest['type'], ['plugin', 'theme', 'game'], true) || !GameSupport::valid($manifest)) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        if (($expectedType && $manifest['type'] !== $expectedType) || ($expectedId && $manifest['id'] !== $expectedId)) {
            throw new RuntimeException(__('system.extensions.identifier_mismatch'));
        }
        new VersionParser()->normalize($manifest['version'] ?? '');
        if (isset($manifest['capabilities']) && !is_array($manifest['capabilities'])) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        if (
            array_key_exists('appearance', $manifest['capabilities'] ?? []) &&
            (($manifest['type'] ?? null) !== 'theme' ||
                !Appearance::validModes($manifest['capabilities']['appearance']))
        ) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        foreach (
            ['requires', 'integrations', 'navigation', 'permissions', 'assets', 'imports', 'build', 'update'] as $section
        ) {
            if (isset($manifest[$section]) && !is_array($manifest[$section])) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
        }
        if (
            (isset($manifest['description']) && !is_string($manifest['description'])) ||
            (isset($manifest['builtin']) && !is_bool($manifest['builtin'])) ||
            (isset($manifest['default']) && !is_bool($manifest['default']))
        ) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        foreach ($manifest['permissions'] ?? [] as $permission => $label) {
            if (
                !is_string($permission) ||
                !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,159}$/D', $permission)
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if (is_string($label)) {
                if (trim($label) === '') {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }

                continue;
            }
            if (
                !is_array($label) ||
                array_diff(array_keys($label), ['label', 'default']) ||
                !is_string($label['label'] ?? null) ||
                trim($label['label']) === ''
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if (array_key_exists('default', $label)) {
                if (
                    !is_array($label['default']) ||
                    array_diff(array_keys($label['default']), ['visitor', 'member', 'mod', 'admin']) ||
                    count(array_filter($label['default'], fn ($enabled) => !is_bool($enabled))) ||
                    in_array($permission, PermissionCatalog::corePermissions(), true) ||
                    ((array_key_exists('visitor', $label['default']) ||
                        array_key_exists('member', $label['default'])) &&
                        preg_match('/(?:^|\.)(?:admin|manage)(?:\.|$)/i', $permission)) ||
                    (!str_starts_with($permission, $manifest['id'].'.') &&
                        !str_starts_with($permission, 'plugins.'.$manifest['id'].'.'))
                ) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
            }
        }
        foreach ($manifest['navigation'] ?? [] as $area => $links) {
            if (!is_array($links)) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if ($area === 'admin') {
                $count = 0;
                $ids = [];
                $this->adminNavigation($links, $directory, 1, $count, $ids);

                continue;
            }
            foreach ($links as $link) {
                if (
                    !is_array($link) ||
                    !is_string($link['label'] ?? null) ||
                    !is_string($link['route'] ?? null) ||
                    !preg_match('/^[A-Za-z0-9_.-]{1,160}$/D', $link['route']) ||
                    (isset($link['permission']) && !is_string($link['permission']))
                ) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
            }
        }
        foreach (['styles', 'scripts'] as $kind) {
            if (isset($manifest['assets'][$kind]) && !is_array($manifest['assets'][$kind])) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            foreach ($manifest['assets'][$kind] ?? [] as $asset) {
                if (
                    !is_string($asset) ||
                    !preg_match('~^[A-Za-z0-9_./-]+$~D', $asset) ||
                    str_contains($asset, '..') ||
                    str_starts_with($asset, '/')
                ) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
            }
        }
        $constraints = app(DependencyConstraints::class);
        $constraints->requirements($manifest);
        foreach (['integrations', 'conflicts'] as $section) {
            $constraints->map(array_key_exists($section, $manifest) ? $manifest[$section] : []);
        }
        if (
            isset($manifest['provider']) ||
            isset($manifest['namespace']) ||
            isset($manifest['privacy']) ||
            $manifest['type'] !== 'theme'
        ) {
            $namespace = $manifest['namespace'] ?? '';
            $provider = $manifest['provider'] ?? '';
            if (
                !is_string($namespace) ||
                !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$/D', $namespace) ||
                !is_string($provider) ||
                !str_starts_with($provider, $namespace) ||
                !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $provider)
            ) {
                throw new RuntimeException(__('system.extensions.invalid_provider'));
            }
            if (!$this->namespaceAvailable($namespace)) {
                throw new RuntimeException(__('system.extensions.invalid_provider'));
            }
            $providerPath =
                $directory.'/src/'.str_replace('\\', '/', substr($provider, strlen($namespace))).'.php';
            if (!is_file($providerPath)) {
                throw new RuntimeException(__('system.extensions.invalid_provider'));
            }
        }
        if (isset($manifest['configuration'])) {
            if (
                !is_array($manifest['configuration']) ||
                (isset($manifest['configuration']['route']) &&
                    (!is_string($manifest['configuration']['route']) ||
                        !preg_match('/^[A-Za-z0-9_.-]{1,160}$/D', $manifest['configuration']['route']))) ||
                (isset($manifest['configuration']['permission']) &&
                    (!is_string($manifest['configuration']['permission']) ||
                        !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,159}$/D', $manifest['configuration']['permission']))) ||
                (isset($manifest['configuration']['schema']) && !is_array($manifest['configuration']['schema']))
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if ($manifest['type'] === 'theme' && isset($manifest['configuration']['schema'])) {
                ThemeConfiguration::validateSchema($manifest['configuration']['schema']);
            }
        }
        $this->themeNavigation($manifest);
        if (array_key_exists('icons', $manifest)) {
            $manifest['icons']['entries'] = $this->themeIcons($manifest, $directory);
        }
        ThemeEditor::validate($manifest, $directory);
        ThemeTemplates::validateManifest($manifest);
        $this->configurationPath($manifest, $directory);
        UninstallData::validate($manifest);
        $this->privacyPath($manifest, $directory);
        app(PrivateLibraries::class)->validate($manifest, $directory);

        return $manifest;
    }

    private function themeNavigation(array $manifest): void
    {
        if (!array_key_exists('navigation_locations', $manifest)) {
            return;
        }
        if (
            $manifest['type'] !== 'theme' ||
            $manifest['target'] !== 'site' ||
            !is_array($manifest['navigation_locations']) ||
            count($manifest['navigation_locations']) > 20
        ) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        foreach ($manifest['navigation_locations'] as $id => $label) {
            if (
                !is_string($id) ||
                !preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $id) ||
                in_array($id, config('community.navigation_locations'), true) ||
                !is_string($label) ||
                trim($label) === '' ||
                strlen($label) > 200
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
        }
    }

    private function themeIcons(array $manifest, string $directory): array
    {
        $library = $manifest['icons'];
        if (
            $manifest['type'] !== 'theme' ||
            ($manifest['target'] ?? null) !== 'site' ||
            !is_array($library) ||
            array_diff(array_keys($library), ['id', 'name', 'catalog']) ||
            !is_string($library['id'] ?? null) ||
            !ContentIcons::validName($library['id']) ||
            !is_string($library['name'] ?? null) ||
            trim($library['name']) === '' ||
            !is_string($library['catalog'] ?? null)
        ) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        $catalog = $this->localFile($directory, $library['catalog'], 'json');
        try {
            $data = json_decode(file_get_contents($catalog), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'), previous: $error);
        }
        if (!($data instanceof stdClass) || (array) $data === []) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }

        $entries = [];
        foreach ($data as $name => $icon) {
            if (
                !ContentIcons::validName((string) $name) ||
                !($icon instanceof stdClass) ||
                array_diff(array_keys((array) $icon), ['name', 'asset']) ||
                !is_string($icon->name ?? null) ||
                trim($icon->name) === '' ||
                !is_string($icon->asset ?? null)
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            $this->localFile($directory.'/public', $icon->asset, 'svg');
            $entries[$name] = (array) $icon;
        }

        return $entries;
    }

    private function localFile(string $directory, string $file, string $extension): string
    {
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9_./-]*\.'.preg_quote($extension, '~').'$~D', $file) || str_contains($file, '..')) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        $root = realpath($directory);
        $path = realpath($directory.'/'.$file);
        if (!$root || !$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }

        return $path;
    }

    public function configurationPath(array $manifest, string $directory): ?string
    {
        if (!array_key_exists('routes', $manifest['configuration'] ?? [])) {
            return null;
        }
        $file = $manifest['configuration']['routes'];
        $root = realpath($directory);
        if (
            $manifest['type'] !== 'theme' ||
            empty($manifest['configuration']['route']) ||
            !is_string($file) ||
            !preg_match('~^routes/[A-Za-z0-9_-]+\.php$~D', $file) ||
            !$root ||
            is_link($directory) ||
            is_link($directory.'/routes') ||
            is_link($directory.'/'.$file)
        ) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        $path = realpath($directory.'/'.$file);
        if (
            !$path ||
            !is_file($path) ||
            str_replace('\\', '/', $path) !== rtrim(str_replace('\\', '/', $root), '/').'/'.$file
        ) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }

        return $path;
    }

    public function namespacesOverlap(string $first, string $second): bool
    {
        $first = strtolower(rtrim($first, '\\'));
        $second = strtolower(rtrim($second, '\\'));

        return $first === $second || str_starts_with($first, $second.'\\') || str_starts_with($second, $first.'\\');
    }

    private function namespaceAvailable(string $namespace): bool
    {
        if (self::$protectedNamespaces === null) {
            $prefixes = [
                'App\\',
                'Tests\\',
                'Illuminate\\',
                'Symfony\\',
                'Composer\\',
                'Laravel\\',
                'KraftInkPrivate\\',
            ];
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                $prefixes = [
                    ...$prefixes,
                    ...array_keys($loader->getPrefixesPsr4()),
                    ...array_keys($loader->getPrefixes()),
                ];
            }
            self::$protectedNamespaces = array_values(array_unique($prefixes));
        }

        return !array_any(self::$protectedNamespaces, fn ($prefix) => $this->namespacesOverlap($namespace, $prefix) || str_ends_with($prefix, '_') && str_starts_with(strtolower($namespace), strtolower($prefix)));
    }

    public function privacyPath(array $manifest, string $directory): ?string
    {
        if (!array_key_exists('privacy', $manifest)) {
            return null;
        }
        $class = $manifest['privacy'];
        $namespace = $manifest['namespace'] ?? '';
        if (
            !is_string($class) ||
            !is_string($namespace) ||
            $namespace === '' ||
            !str_starts_with($class, $namespace) ||
            !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $class)
        ) {
            throw new RuntimeException(__('site.account.privacy.exports.invalid_handler'));
        }
        $root = realpath($directory.'/src');
        $path = realpath($directory.'/src/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php');
        if (
            !$root ||
            !$path ||
            !is_file($path) ||
            !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $root), '/').'/')
        ) {
            throw new RuntimeException(__('site.account.privacy.exports.invalid_handler'));
        }

        return $path;
    }

    private function adminNavigation(array $links, string $directory, int $depth, int &$count, array &$ids): void
    {
        if ($depth > 4 || !array_is_list($links)) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        foreach ($links as $link) {
            if (
                ++$count > 200 ||
                !is_array($link) ||
                !is_string($link['label'] ?? null) ||
                !preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,199}$/D', $link['label']) ||
                (isset($link['permission']) &&
                    (!is_string($link['permission']) ||
                        !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,159}$/D', $link['permission'])))
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if (array_key_exists('permissions', $link)) {
                $permissions = $link['permissions'];
                if (
                    array_key_exists('permission', $link) ||
                    !is_array($permissions) ||
                    !array_is_list($permissions) ||
                    $permissions === [] ||
                    count($permissions) > 50 ||
                    count(array_unique($permissions, SORT_REGULAR)) !== count($permissions)
                ) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
                foreach ($permissions as $permission) {
                    if (!is_string($permission) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,159}$/D', $permission)) {
                        throw new RuntimeException(__('system.extensions.invalid_manifest'));
                    }
                }
            }
            $hasRoute = isset($link['route']);
            $hasChildren = isset($link['children']);
            if (
                $hasRoute === $hasChildren ||
                ($hasRoute &&
                    (!is_string($link['route']) || !preg_match('/^[A-Za-z0-9_.-]{1,160}$/D', $link['route']))) ||
                ($hasChildren && (!is_array($link['children']) || $link['children'] === []))
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if (
                array_key_exists('parent', $link) &&
                $link['parent'] !== null &&
                (!is_string($link['parent']) || !preg_match('/^[a-z][a-z0-9-]{0,99}$/D', $link['parent']))
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if (
                (isset($link['section']) &&
                    !in_array($link['section'], ['site', 'community', 'administration'], true)) ||
                (isset($link['position']) &&
                    (!is_int($link['position']) || $link['position'] < 0 || $link['position'] > 9999))
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if (isset($link['id'])) {
                if (
                    !is_string($link['id']) ||
                    !preg_match('/^[a-z][a-z0-9-]{0,49}$/D', $link['id']) ||
                    isset($ids[$link['id']])
                ) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
                $ids[$link['id']] = true;
            }
            if (isset($link['icon'])) {
                $icon = $link['icon'];
                if (is_string($icon)) {
                    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $icon)) {
                        throw new RuntimeException(__('system.extensions.invalid_manifest'));
                    }
                } elseif (is_array($icon) && array_keys($icon) === ['asset'] && is_string($icon['asset'])) {
                    $asset = $icon['asset'];
                    $root = realpath($directory.'/public');
                    $path = realpath($directory.'/public/'.$asset);
                    if (
                        !preg_match('~^[A-Za-z0-9][A-Za-z0-9_./-]*\.(?:svg|png|webp)$~Di', $asset) ||
                        str_contains($asset, '..') ||
                        !$root ||
                        !$path ||
                        !is_file($path) ||
                        !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $root), '/').'/')
                    ) {
                        throw new RuntimeException(__('system.extensions.invalid_manifest'));
                    }
                } else {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
            }
            if ($hasChildren) {
                $this->adminNavigation($link['children'], $directory, $depth + 1, $count, $ids);
            }
        }
    }

    public function compatible(array $manifest): void
    {
        $errors = $this->compatibilityErrors($manifest);
        if ($errors) {
            throw new RuntimeException(implode(PHP_EOL, $errors));
        }
    }

    public function compatibilityErrors(array $manifest): array
    {
        $requirements = app(DependencyConstraints::class)->requirements($manifest);
        $errors = [];
        if (!Semver::satisfies(explode('-', config('manifest.version'))[0], $requirements['cms'])) {
            $errors[] = __('system.extensions.incompatible_cms');
        }
        if (!Semver::satisfies(PHP_VERSION, $requirements['php'])) {
            $errors[] = __('system.extensions.incompatible_php');
        }

        return $errors;
    }
}
