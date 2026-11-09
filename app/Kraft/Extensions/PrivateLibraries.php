<?php

namespace App\Kraft\Extensions;

use Composer\Autoload\ClassLoader;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use DirectoryIterator;
use RuntimeException;
use Throwable;
use JsonException;

class PrivateLibraries
{
    private static array $loaded = [];

    public function validate(array $manifest, string $directory): void
    {
        $this->inspect($manifest, $directory);
    }

    /**
     * @throws Throwable
     */
    public function load(array $extension): void
    {
        $libraries = $this->inspect($extension, $extension['path']);
        if ($libraries === null) {
            return;
        }
        $prefix = $libraries['prefix'];
        $identity = [$libraries['vendor'], $extension['libraries']['sha256']];
        if (isset(self::$loaded[$prefix])) {
            if (self::$loaded[$prefix]['identity'] !== $identity) {
                $this->invalid();
            }

            return;
        }
        $loader = new ClassLoader();
        foreach ($libraries['psr4'] as $namespace => $paths) {
            $loader->addPsr4($namespace, $paths);
        }
        $loader->addClassMap($libraries['classmap']);
        $loader->register();
        self::$loaded[$prefix] = ['identity' => $identity, 'loader' => $loader];
        try {
            foreach ($libraries['files'] as $file) {
                (static function (string $path): void {
                    require_once $path;
                })($file);
            }
        } catch (Throwable $error) {
            $loader->unregister();
            unset(self::$loaded[$prefix]);
            throw $error;
        }
    }

    private function inspect(array $manifest, string $directory): ?array
    {
        if (!array_key_exists('libraries', $manifest)) {
            if (
                file_exists($directory.'/vendor') ||
                is_link($directory.'/vendor') ||
                $this->hasDependencies($directory)
            ) {
                $this->invalid();
            }

            return null;
        }
        $descriptor = $manifest['libraries'];
        if (
            !in_array($manifest['type'] ?? null, ['plugin', 'theme', 'game'], true) ||
            !is_string($manifest['id'] ?? null) ||
            !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $manifest['id'])
        ) {
            $this->invalid();
        }
        $this->noLinks($directory);
        $prefix =
            'KraftInkPrivate\\P'.substr(hash('sha256', $manifest['type'].':'.$manifest['id']), 0, 24).'\\';
        if (
            !is_array($descriptor) ||
            array_diff(array_keys($descriptor), ['prefix', 'manifest', 'sha256']) ||
            ($descriptor['prefix'] ?? null) !== $prefix ||
            ($descriptor['manifest'] ?? null) !== 'vendor/kraftink.json' ||
            !is_string($descriptor['sha256'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $descriptor['sha256']) ||
            is_link($directory) ||
            is_link($directory.'/vendor') ||
            !is_dir($directory.'/vendor')
        ) {
            $this->invalid();
        }
        $vendor = realpath($directory.'/vendor');
        $index = $this->path($vendor, 'kraftink.json', true);
        if (filesize($index) > 4194304 || !hash_equals($descriptor['sha256'], hash_file('sha256', $index))) {
            $this->invalid();
        }
        try {
            $metadata = json_decode(file_get_contents($index), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'), previous: $error);
        }
        if (
            !is_array($metadata) ||
            array_diff(array_keys($metadata), ['psr4', 'classmap', 'files', 'hashes', 'platform']) ||
            !is_array($metadata['psr4'] ?? null) ||
            !is_array($metadata['classmap'] ?? null) ||
            !is_array($metadata['files'] ?? null) ||
            !array_is_list($metadata['files']) ||
            !is_array($metadata['hashes'] ?? null) ||
            !is_array($metadata['platform'] ?? null) ||
            count($metadata['psr4']) > 10000 ||
            count($metadata['classmap']) > 25000 ||
            count($metadata['files']) > 10000 ||
            count($metadata['hashes']) > 10000
        ) {
            $this->invalid();
        }
        $inventory = [];
        $size = 0;
        $entries = 0;
        $this->inventory($vendor, '', $inventory, $size, $entries);
        $hashes = $metadata['hashes'];
        ksort($inventory);
        ksort($hashes);
        if (array_keys($inventory) !== array_keys($hashes)) {
            $this->invalid();
        }
        foreach ($hashes as $relative => $hash) {
            if (
                !is_string($hash) ||
                !preg_match('/^[a-f0-9]{64}$/D', $hash) ||
                !hash_equals($hash, hash_file('sha256', $inventory[$relative]))
            ) {
                $this->invalid();
            }
        }
        $psr4 = [];
        foreach ($metadata['psr4'] as $namespace => $paths) {
            if (
                !$this->symbol($namespace, $prefix, true) ||
                !is_array($paths) ||
                !array_is_list($paths) ||
                $paths === []
            ) {
                $this->invalid();
            }
            $psr4[$namespace] = [];
            foreach ($paths as $path) {
                $psr4[$namespace][] = $this->path($vendor, $path, false);
            }
        }
        $classmap = [];
        foreach ($metadata['classmap'] as $class => $path) {
            if (!$this->symbol($class, $prefix, false)) {
                $this->invalid();
            }
            $classmap[$class] = $this->phpFile($vendor, $path, $inventory);
        }
        $files = [];
        foreach ($metadata['files'] as $path) {
            $files[] = $this->phpFile($vendor, $path, $inventory);
        }
        if (count(array_unique($files)) !== count($files)) {
            $this->invalid();
        }
        $this->platform($metadata['platform']);

        return compact('prefix', 'vendor', 'psr4', 'classmap', 'files');
    }

    private function hasDependencies(string $directory): bool
    {
        $file = $directory.'/composer.json';
        if (!file_exists($file) && !is_link($file)) {
            return false;
        }
        if (is_link($file) || !is_file($file) || filesize($file) > 262144) {
            $this->invalid();
        }
        try {
            $composer = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'), previous: $error);
        }
        if (!is_array($composer) || !is_array($composer['require'] ?? [])) {
            $this->invalid();
        }

        return array_any($composer['require'] ?? [], fn ($constraint, $package) => is_string($package) && str_contains($package, '/'));
    }

    private function inventory(string $vendor, string $relative, array &$files, int &$size, int &$entries): void
    {
        if (substr_count($relative, '/') > 32) {
            $this->invalid();
        }
        foreach (new DirectoryIterator($vendor.($relative === '' ? '' : '/'.$relative)) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if (++$entries > 10000) {
                $this->invalid();
            }
            $path = ($relative === '' ? '' : $relative.'/').$entry->getFilename();
            if ($entry->isLink()) {
                $this->invalid();
            }
            if ($entry->isDir()) {
                $this->path($vendor, $path, false);
                $this->inventory($vendor, $path, $files, $size, $entries);
            } elseif ($entry->isFile()) {
                if ($path === 'kraftink.json') {
                    continue;
                }
                $files[$path] = $this->path($vendor, $path, true);
                $size += $entry->getSize();
                if (count($files) > 10000 || $size > 104857600) {
                    $this->invalid();
                }
            } else {
                $this->invalid();
            }
        }
    }

    private function path(string $vendor, mixed $relative, bool $file): string
    {
        if (
            !is_string($relative) ||
            $relative === '' ||
            strlen($relative) > 512 ||
            preg_match('~[\\\\:<>"|?*\x00-\x1f\x7f]~', $relative)
        ) {
            $this->invalid();
        }
        $path = $vendor;
        foreach (explode('/', $relative) as $segment) {
            if (
                $segment === '' ||
                $segment === '.' ||
                $segment === '..' ||
                preg_match('/[. ]$/D', $segment) ||
                preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment)
            ) {
                $this->invalid();
            }
            $path .= '/'.$segment;
            if (is_link($path)) {
                $this->invalid();
            }
        }
        $resolved = realpath($path);
        $normalize = fn (string $value): string => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $value))
            : str_replace('\\', '/', $value);
        if (
            !$resolved ||
            !str_starts_with($normalize($resolved), rtrim($normalize($vendor), '/').'/') ||
            ($file ? !is_file($resolved) : !is_dir($resolved))
        ) {
            $this->invalid();
        }

        return $resolved;
    }

    private function phpFile(string $vendor, mixed $path, array $inventory): string
    {
        if (!is_string($path) || !preg_match('/\.(?:php|inc)$/Di', $path) || !isset($inventory[$path])) {
            $this->invalid();
        }

        return $this->path($vendor, $path, true);
    }

    private function noLinks(string $path): void
    {
        for ($cursor = $path; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) {
                $this->invalid();
            }
        }
    }

    private function symbol(mixed $symbol, string $prefix, bool $namespace): bool
    {
        return is_string($symbol) &&
            str_starts_with($symbol, $prefix) &&
            preg_match(
                $namespace
                    ? '/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$/D'
                    : '/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D',
                $symbol,
            ) === 1;
    }

    private function platform(array $requirements): void
    {
        foreach ($requirements as $package => $constraint) {
            if (
                !is_string($package) ||
                !preg_match('/^(?:php(?:-64bit)?|ext-[a-z0-9_][a-z0-9_-]*)$/D', $package) ||
                !is_string($constraint) ||
                trim($constraint) === '' ||
                strlen($constraint) > 2000
            ) {
                $this->invalid();
            }
            new VersionParser()->parseConstraints($constraint);
            $version = match (true) {
                $package === 'php' => PHP_VERSION,
                $package === 'php-64bit' => PHP_INT_SIZE === 8 ? PHP_VERSION : false,
                default => extension_loaded(substr($package, 4)) ? (phpversion(substr($package, 4)) ?: '0') : false,
            };
            if ($version === false || !Semver::satisfies($version, $constraint)) {
                throw new RuntimeException(
                    __('system.extensions.library_platform', ['package' => $package, 'constraint' => $constraint]),
                );
            }
        }
    }

    private function invalid(): never
    {
        throw new RuntimeException(__('system.extensions.invalid_manifest'));
    }
}
