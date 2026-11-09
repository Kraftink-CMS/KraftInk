<?php

declare(strict_types=1);

use App\Kraft\Setup\InstallationFiles;
use Random\RandomException;

class KraftOperationGate
{
    /** @throws Throwable */
    public static function start(string $storage): void
    {
        if (PHP_SAPI === 'cli' && !self::queueCommand()) {
            return;
        }
        require_once dirname(__DIR__).'/app/Kraft/Setup/InstallationFiles.php';
        if (!InstallationFiles::installed($storage)) {
            return;
        }
        $path = rtrim($storage, '/\\').'/app/operations.lock';
        $handle = self::open($path);
        try {
            if (!flock($handle, LOCK_SH | LOCK_NB)) {
                throw new RuntimeException('The application is being changed.');
            }
            $GLOBALS['kraft.operations'] = [
                'handle' => $handle,
                'path' => $path,
                'generation' => self::generation($path),
            ];
        } catch (Throwable $exception) {
            fclose($handle);
            throw $exception;
        }
        register_shutdown_function(function (): void {
            $handle = $GLOBALS['kraft.operations']['handle'] ?? null;
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        });
    }

    public static function queueCommand(): bool
    {
        return array_intersect(
            ['queue:work', 'queue:listen', 'kraft:tasks-run'],
            array_slice($_SERVER['argv'] ?? [], 1),
        ) !== [];
    }

    public static function open(string $path): mixed
    {
        self::boundary($path);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Cannot prepare operation coordination.');
        }
        $handle = @fopen($path, 'c+');
        if (!$handle || !@chmod($path, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Cannot protect operation coordination.');
        }

        return $handle;
    }

    public static function generation(string $lock): string
    {
        $path = self::generationPath($lock);
        self::boundary($path);
        if (!file_exists($path)) {
            return '';
        }
        $value = @file_get_contents($path, false, null, 0, 65);
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new RuntimeException('Invalid operation generation.');
        }

        return $value;
    }

    public static function advance(string $lock): void
    {
        $path = self::generationPath($lock);
        self::boundary($path);
        try {
            $temporary = $path.'.'.bin2hex(random_bytes(8)).'.new';
            $generation = bin2hex(random_bytes(32));
        } catch (RandomException $exception) {
            throw new RuntimeException('Cannot securely reserve an operation generation.', 0, $exception);
        }
        $handle = @fopen($temporary, 'xb');
        if (!$handle) {
            throw new RuntimeException('Cannot reserve an operation generation.');
        }
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot protect an operation generation.');
            }
            if (fwrite($handle, $generation) !== 64 || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot persist an operation generation.');
            }
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Cannot publish an operation generation.');
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

    public static function updating(string $storage): bool
    {
        $data = @file_get_contents(rtrim($storage, '/\\').'/framework/down', false, null, 0, 4096);
        $state = is_string($data) ? json_decode($data, true) : null;

        return is_array($state) && !empty($state['kraft_update']);
    }

    private static function generationPath(string $lock): string
    {
        return str_ends_with($lock, '.lock') ? substr($lock, 0, -5).'.generation' : $lock.'.generation';
    }

    private static function boundary(string $path): void
    {
        $normalize = fn (string $value) => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $value))
            : $value;
        $cursor = $path;
        while ($cursor !== dirname($cursor)) {
            if (
                is_link($cursor) ||
                (file_exists($cursor) && $normalize(realpath($cursor) ?: '') !== $normalize($cursor))
            ) {
                throw new RuntimeException('Operation coordination requires canonical paths.');
            }
            $cursor = dirname($cursor);
        }
    }
}
