<?php

namespace App\Kraft\Setup;

use RuntimeException;

class InstallationFiles
{
    public const string DIRECTORY = 'app/private/installation';

    public const string MARKER = self::DIRECTORY.'/installed.json';

    public const string ACCESS = self::DIRECTORY.'/access.json';

    public const string LOCK = self::DIRECTORY.'/install.lock';

    public static function installed(string $storage): bool
    {
        return is_file(self::path($storage, self::MARKER));
    }

    public static function access(string $storage): string
    {
        return self::path($storage, self::ACCESS);
    }

    /** @return resource|null */
    public static function lock(string $storage): mixed
    {
        $path = self::path($storage, self::LOCK);
        self::prepare($path);
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'c+b');
        } finally {
            umask($mask);
        }
        if (!$handle) {
            throw new RuntimeException('Cannot open installation coordination.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        if (!chmod($path, 0600)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Cannot protect installation coordination.');
        }

        return $handle;
    }

    public static function journal(string $storage, string $identity): string
    {
        return self::path($storage, self::DIRECTORY.'/journals/'.$identity.'.json');
    }

    public static function path(string $storage, string $relative): string
    {
        return rtrim($storage, '/\\').'/'.$relative;
    }

    public static function prepare(string $path, bool $createDirectory = true): void
    {
        $normalize = fn (string $value): string => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $value))
            : $value;
        for ($cursor = $path; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (
                is_link($cursor) ||
                (file_exists($cursor) && $normalize(realpath($cursor) ?: '') !== $normalize($cursor))
            ) {
                throw new RuntimeException('Installation storage requires canonical paths.');
            }
        }
        $stat = file_exists($path) ? @stat($path) : false;
        if (is_array($stat) && (($stat['nlink'] ?? 1) > 1 || !is_file($path))) {
            throw new RuntimeException('Installation storage requires a private file.');
        }
        $directory = dirname($path);
        if ($createDirectory && !is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot prepare installation storage.');
        }
    }
}
