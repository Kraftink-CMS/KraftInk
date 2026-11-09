<?php

declare(strict_types=1);

use Random\RandomException;

class KraftUpdateFiles
{
    private static ?array $protected = null;

    public static function configure(array $rules): void
    {
        self::$protected = self::protectionRules($rules);
    }

    public static function protectionRules(array $rules): array
    {
        if (!array_is_list($rules) || count($rules) > 1000) {
            throw new RuntimeException('Invalid permanent update protection rules.');
        }
        $protected = [];
        foreach ($rules as $rule) {
            $descriptor = is_string($rule) ? ['pattern' => $rule] : $rule;
            $scope = is_array($descriptor) && array_key_exists('scope', $descriptor) ? $descriptor['scope'] : 'all';
            $except = is_array($descriptor) && array_key_exists('except', $descriptor) ? $descriptor['except'] : [];
            if (
                !is_array($descriptor) ||
                array_diff(array_keys($descriptor), ['pattern', 'except', 'scope']) ||
                !is_string($descriptor['pattern'] ?? null) ||
                !in_array($scope, ['all', 'core', 'package'], true) ||
                !is_array($except) ||
                !array_is_list($except) ||
                count($except) > 1000
            ) {
                throw new RuntimeException('Invalid permanent update protection rule.');
            }
            $pattern = self::pattern($descriptor['pattern']);
            $exceptions = [];
            foreach ($except as $exception) {
                if (!is_string($exception)) {
                    throw new RuntimeException('Invalid permanent update protection exception.');
                }
                $exceptions[] = self::pattern($exception);
            }
            $protected[] = [
                'pattern' => $pattern,
                'except' => $exceptions,
                'scope' => $scope,
            ];
        }

        return $protected;
    }

    public static function protection(): array
    {
        if (self::$protected === null) {
            if (!class_exists(KraftManifest::class, false)) {
                require_once __DIR__.'/manifest.php';
            }
            $manifest = KraftManifest::read(dirname(__DIR__), 'cms', 'kraftink');
            self::configure($manifest['update']['protected']);
        }

        return self::$protected;
    }

    public static function validate(
        string $path,
        ?string $scope = null,
        array $builtins = [],
        bool $allowPreserved = false,
        bool $directory = false,
    ): void {
        self::syntax($path);
        if ($allowPreserved && $scope === null && self::preserved($path)) {
            return;
        }
        if ($scope === null && preg_match('~^extensions(?:/|$)~i', $path)) {
            if (!class_exists(KraftBuiltinPackages::class, false) && is_file(__DIR__.'/builtins.php')) {
                require_once __DIR__.'/builtins.php';
            }
            $builtinScope =
                $builtins && class_exists(KraftBuiltinPackages::class, false)
                    ? KraftBuiltinPackages::scope($path, $builtins)
                    : null;
            if ($builtinScope === null) {
                throw new RuntimeException('The core update cannot change an external extension.');
            }
            self::validate($path, $builtinScope, [], $allowPreserved, $directory);

            return;
        }
        if ($scope !== null) {
            if (
                !preg_match(
                    '~^extensions/(?:bundled|installed)/(?:plugins|themes|games)/[a-z][a-z0-9-]{1,49}$~D',
                    $scope,
                ) ||
                (!str_starts_with($path, $scope.'/') && !($directory && $path === $scope))
            ) {
                throw new RuntimeException('The extension update leaves its package boundary.');
            }
            $relative = substr($path, strlen($scope) + 1);
            if (!$allowPreserved && self::preserved($relative, true)) {
                throw new RuntimeException('Mutable extension files are protected.');
            }
        } elseif (!$allowPreserved && self::preserved($path)) {
            throw new RuntimeException('Protected update path.');
        }
    }

    public static function preservation(mixed $rules, array $required = []): array
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 1000) {
            throw new RuntimeException('Invalid update preservation rules.');
        }
        $preserve = [];
        foreach ($rules as $prefix) {
            if (!is_string($prefix)) {
                throw new RuntimeException('Invalid update preservation rule.');
            }
            $prefix = strtolower(rtrim($prefix, '/'));
            self::syntax($prefix);
            foreach ($required as $path) {
                $path = strtolower($path);
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    throw new RuntimeException('An update preservation rule covers required release metadata.');
                }
            }
            $preserve[$prefix] = $prefix;
        }

        return array_values($preserve);
    }

    public static function synchronization(mixed $rules): array
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 1000) {
            throw new RuntimeException('Invalid update synchronization directories.');
        }
        $directories = [];
        foreach ($rules as $directory) {
            if (!is_string($directory)) {
                throw new RuntimeException('Invalid update synchronization directory.');
            }
            $directory = rtrim($directory, '/');
            self::syntax($directory);
            $canonical = strtolower($directory);
            if (isset($directories[$canonical]) && $directories[$canonical] !== $directory) {
                throw new RuntimeException('Case-ambiguous update synchronization directories.');
            }
            $directories[$canonical] = $directory;
        }

        return array_values($directories);
    }

    public static function synchronize(
        string $root,
        array $directories,
        array $files,
        array $preserve = [],
        ?string $scope = null,
        array $builtins = [],
        array $protected = [],
    ): array {
        $inventory = array_fill_keys(
            PHP_OS_FAMILY === 'Windows' ? array_map('strtolower', array_keys($files)) : array_keys($files),
            true,
        );
        $preserve = self::preservation($preserve);
        $deleted = [];
        $scan = function (string $path, bool $directory = false) use (
            &$scan,
            &$deleted,
            $root,
            $inventory,
            $preserve,
            $scope,
            $builtins,
            $protected,
        ): void {
            $directory = $directory || is_dir($root.'/'.$path);
            self::validate($path, $scope, $builtins, true, $directory);
            $packageScope = $scope ?? ($builtins ? KraftBuiltinPackages::scope($path, $builtins) : null);
            $relative = $packageScope === null ? $path : substr($path, strlen($packageScope) + 1);
            if (
                self::preserved($relative, $packageScope !== null) ||
                self::matches($path, $preserve) ||
                self::local($root, $path, $protected)
            ) {
                return;
            }
            $target = self::target($root, $path, $scope, $builtins, $protected, $directory);
            if (is_dir($target)) {
                foreach (new DirectoryIterator($target) as $entry) {
                    if (!$entry->isDot()) {
                        $scan($path.'/'.$entry->getFilename());
                    }
                }
            } elseif (is_file($target)) {
                $canonical = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
                if (!isset($inventory[$canonical])) {
                    $deleted[$path] = $path;
                    if (count($deleted) > 50000) {
                        throw new RuntimeException('The update synchronization inventory is too large.');
                    }
                }
            } elseif (file_exists($target)) {
                throw new RuntimeException('Synchronization requires regular files and directories.');
            }
        };
        foreach (self::synchronization($directories) as $directory) {
            $scan($directory, true);
        }
        sort($deleted, SORT_STRING);

        return $deleted;
    }

    public static function preserved(string $path, bool $extension = false, array $preserve = []): bool
    {
        return self::protected($path, self::protection(), $extension) || self::matches($path, $preserve);
    }

    public static function protected(string $path, array $rules, bool $extension = false): bool
    {
        $path = strtolower($path);
        $prefixes = [];
        $prefix = '';
        foreach (explode('/', $path) as $part) {
            $prefix = $prefix === '' ? $part : $prefix.'/'.$part;
            $prefixes[] = $prefix;
        }
        foreach ($rules as $rule) {
            if ($rule['scope'] !== 'all' && $rule['scope'] !== ($extension ? 'package' : 'core')) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (
                    self::glob($prefix, $rule['pattern']) &&
                    !array_any($rule['except'], fn ($pattern) => self::glob($prefix, $pattern))
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function matches(string $path, array $preserve): bool
    {
        $path = strtolower($path);
        foreach (self::preservation($preserve) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    public static function occupied(string $root, string $path): bool
    {
        self::syntax($path);
        $current = realpath($root);
        if ($current === false || !is_dir($current)) {
            throw new RuntimeException('The update boundary is missing.');
        }
        $parts = explode('/', $path);
        foreach ($parts as $index => $part) {
            $current .= DIRECTORY_SEPARATOR.$part;
            if (is_link($current)) {
                return true;
            }
            if (!file_exists($current)) {
                return false;
            }
            if ($index === array_key_last($parts) || !is_dir($current)) {
                return true;
            }
        }

        return false;
    }

    private static function pattern(string $pattern): string
    {
        $pattern = strtolower(rtrim($pattern, '/'));
        if (str_ends_with($pattern, '/**')) {
            $pattern = substr($pattern, 0, -3);
        }
        if (str_contains($pattern, '***')) {
            throw new RuntimeException('Invalid permanent update protection pattern.');
        }
        self::syntax(str_replace('*', 'x', $pattern));

        return $pattern;
    }

    private static function glob(string $path, string $pattern): bool
    {
        $segments = explode('/', $pattern);
        $expression = '';
        foreach ($segments as $index => $segment) {
            if ($segment === '**') {
                $expression .= $index === count($segments) - 1 ? '.*' : '(?:[^/]+/)*';
            } else {
                $expression .= str_replace('\\*', '[^/]*', preg_quote($segment, '~'));
                if ($index !== count($segments) - 1) {
                    $expression .= '/';
                }
            }
        }

        return preg_match('~^'.$expression.'$~D', $path) === 1;
    }

    public static function target(
        string $root,
        string $path,
        ?string $scope = null,
        array $builtins = [],
        array $protected = [],
        bool $allowDirectory = false,
    ): string {
        self::validate($path, $scope, $builtins, false, $allowDirectory);
        $directory = realpath($root);
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('The update boundary is missing.');
        }
        $current = $directory;
        foreach (explode('/', $path) as $part) {
            if (file_exists($current) && !is_dir($current)) {
                throw new RuntimeException('A file obstructs an update directory.');
            }
            $current .= DIRECTORY_SEPARATOR.$part;
            if (is_link($current)) {
                throw new RuntimeException('Symbolic links are forbidden in update paths.');
            }
            if (file_exists($current)) {
                $actual = realpath($current);
                $same =
                    PHP_OS_FAMILY === 'Windows' ? strcasecmp((string) $actual, $current) === 0 : $actual === $current;
                if (!$same) {
                    throw new RuntimeException('The update path resolves outside its expected location.');
                }
            }
        }
        if (!$allowDirectory && is_dir($current)) {
            throw new RuntimeException('Update operations must target files.');
        }
        if ($allowDirectory && file_exists($current) && !is_dir($current)) {
            throw new RuntimeException('Synchronization must target directories.');
        }
        if (self::local($directory, $path, $protected)) {
            throw new RuntimeException('Configured local data is protected from update file operations.');
        }

        return $current;
    }

    public static function local(string $root, string $path, array $protected): bool
    {
        $normalize = static function (string $path): string {
            $path = rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');

            return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        };
        $directory = realpath($root) ?: $root;
        $target = $normalize($directory.'/'.$path);
        foreach ($protected as $local) {
            if (!is_string($local) || $local === '') {
                throw new RuntimeException('Invalid protected local path.');
            }
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $local)) {
                $local = $directory.'/'.$local;
            }
            $local = $normalize($local);
            if ($target === $local || str_starts_with($target, $local.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function syntax(string $path): void
    {
        if ($path === '' || strlen($path) > 500) {
            throw new RuntimeException('Invalid update path.');
        }
        foreach (explode('/', $path) as $part) {
            if (
                !preg_match('/^[A-Za-z0-9_@.-]+$/D', $part) ||
                $part === '.' ||
                $part === '..' ||
                str_ends_with($part, '.') ||
                preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/iD', $part)
            ) {
                throw new RuntimeException('Unsafe update path component.');
            }
        }
    }

    public static function privateWrite(string $path, string $contents): void
    {
        try {
            $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
        } catch (RandomException $exception) {
            throw new RuntimeException('Cannot securely reserve a private update file.', 0, $exception);
        }
        $previousMask = umask(0077);
        try {
            $handle = fopen($temporary, 'xb');
        } finally {
            umask($previousMask);
        }
        if ($handle === false) {
            throw new RuntimeException('Cannot create a private update file.');
        }
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot protect a private update file.');
            }
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot persist a private update file.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Cannot flush a private update file.');
            }
            fclose($handle);
            $handle = null;
            if (PHP_OS_FAMILY === 'Windows') {
                $deadline = microtime(true) + 1;
                do {
                    $renamed = @rename($temporary, $path);
                    $remaining = $deadline - microtime(true);
                    if ($renamed || $remaining <= 0) {
                        break;
                    }
                    usleep(min(20000, (int) ($remaining * 1000000)));
                } while (true);
            } else {
                $renamed = rename($temporary, $path);
            }
            if (!$renamed || !chmod($path, 0600)) {
                throw new RuntimeException('Cannot finalize a private update file.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function uninstallTarget(string $root, string $path, string $id): string
    {
        if (
            !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $id) ||
            !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $path) ||
            (!str_starts_with($path, $id.'/') && $path !== 'editor-images/'.$id)
        ) {
            throw new RuntimeException('Uninstall storage must belong to its extension.');
        }
        foreach (explode('/', $path) as $part) {
            if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/iD', $part)) {
                throw new RuntimeException('Uninstall storage contains an unsafe path component.');
            }
        }
        $root = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim($root, '/\\'));
        $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        for ($cursor = $target; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) {
                throw new RuntimeException('Uninstall storage cannot contain symbolic links.');
            }
            if (file_exists($cursor)) {
                $actual = realpath($cursor);
                $same = PHP_OS_FAMILY === 'Windows' ? strcasecmp((string) $actual, $cursor) === 0 : $actual === $cursor;
                if (!$same) {
                    throw new RuntimeException('Uninstall storage requires canonical paths.');
                }
            }
        }

        return $target;
    }

    public static function uninstallFingerprint(string $path): array
    {
        if (is_link($path)) {
            throw new RuntimeException('Uninstall storage cannot contain symbolic links.');
        }
        if (is_file($path)) {
            return ['.' => hash_file('sha256', $path)];
        }
        if (!is_dir($path)) {
            throw new RuntimeException('Uninstall storage is unavailable.');
        }
        $files = ['.' => null];
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            ) as $file
        ) {
            if ($file->isLink() || (!$file->isFile() && !$file->isDir())) {
                throw new RuntimeException('Uninstall storage contains an unsupported file.');
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1));
            $files[$relative] = $file->isFile() ? hash_file('sha256', $file->getPathname()) : null;
        }
        ksort($files);

        return $files;
    }
}
