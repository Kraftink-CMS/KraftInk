<?php

namespace App\Kraft\System;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Closure;
use KraftDatabaseSnapshot;

class DatabaseSnapshots
{
    public function exclusive(Closure $operation): mixed
    {
        return app(OperationLock::class)->exclusive($operation);
    }

    public function describe(): array
    {
        $database = DB::connection()->getConfig();
        $driver = $database['driver'] ?? null;
        if (!in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException('Database snapshots support SQLite, MySQL/MariaDB and PostgreSQL.');
        }
        if (($database['prefix'] ?? '') !== '') {
            throw new RuntimeException('Database snapshots cannot discard a configured table prefix.');
        }
        foreach (
            [
                'url',
                'read',
                'write',
                'unix_socket',
                'socket',
                'options',
                'ssl_ca',
                'ssl_cert',
                'ssl_key',
                'ssl_cipher',
                'ssl_mode',
                ...($driver === 'pgsql' ? ['sslcert', 'sslkey', 'sslrootcert'] : ['sslmode']),
            ] as $option
        ) {
            if (!empty($database[$option])) {
                throw new RuntimeException(
                    'Database snapshots cannot discard URL, replication, socket, PDO or TLS options.',
                );
            }
        }
        if ($driver === 'pgsql' && !in_array($database['search_path'] ?? $database['schema'] ?? 'public', ['public', ['public']], true)) {
            throw new RuntimeException('PostgreSQL snapshots require the public schema.');
        }
        $path = null;
        $tools = [];
        if ($driver === 'sqlite') {
            if (($database['database'] ?? '') === ':memory:') {
                throw new RuntimeException('A persistent SQLite database is required for a snapshot.');
            }
            $path = realpath($database['database']) ?: realpath(base_path($database['database']));
            if (!$path) {
                throw new RuntimeException('A persistent SQLite database is required for a snapshot.');
            }
        } else {
            foreach (['host', 'database', 'username'] as $key) {
                if (!isset($database[$key]) || !is_string($database[$key])) {
                    throw new RuntimeException('A single explicit database connection is required for a snapshot.');
                }
            }
            $finder = new ExecutableFinder();
            if ($driver === 'pgsql') {
                $tools = [
                    'dump' => config('kraft.update_tools.pgsql.dump') ?: $finder->find('pg_dump'),
                    'restore' => config('kraft.update_tools.pgsql.restore') ?: $finder->find('psql'),
                ];
            } else {
                $family = $driver === 'mariadb' ? 'mariadb' : 'mysql';
                $tools = [
                    'dump' => config('kraft.update_tools.'.$driver.'.dump') ?:
                        config('kraft.update_tools.mysql.dump') ?:
                        $finder->find($family === 'mariadb' ? 'mariadb-dump' : 'mysqldump'),
                    'restore' => config('kraft.update_tools.'.$driver.'.restore') ?:
                        config('kraft.update_tools.mysql.restore') ?:
                        $finder->find($family),
                ];
            }
        }

        return [
            'database_driver' => $driver,
            'database' => $path,
            'connection' => array_intersect_key($database, array_flip([
                'host', 'port', 'database', 'username',
                ...($driver === 'pgsql' ? ['sslmode'] : []),
            ])),
            'tools' => $tools,
        ];
    }

    public function make(string $directory, ?array $expectedState = null): KraftDatabaseSnapshot
    {
        $state = $this->describe();
        if ($expectedState !== null) {
            foreach (['database_driver', 'database', 'connection'] as $key) {
                if (!array_key_exists($key, $expectedState) || $expectedState[$key] !== $state[$key]) {
                    throw new RuntimeException(
                        'The current database identity does not match the saved snapshot context.',
                    );
                }
            }
        }
        $root = realpath(storage_path('app'));
        $normalize = function (string $path): string {
            $normalized = str_replace('\\', '/', rtrim($path, '\\/'));

            return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
        };
        if (
            !$root ||
            $normalize($directory) === $normalize($root) ||
            !str_starts_with($normalize($directory).'/', $normalize($root).'/') ||
            preg_match('~(?:^|[\\\\/])\.{1,2}(?:[\\\\/]|$)|\x00~', $directory)
        ) {
            throw new RuntimeException('Snapshot storage must stay inside the private application directory.');
        }
        $publicRoots = [storage_path('app/public')];
        $publicRoot = config('filesystems.disks.public.root');
        if (config('filesystems.disks.public.driver') === 'local' && is_string($publicRoot) && $publicRoot !== '') {
            $publicRoots[] = $publicRoot;
        }
        foreach ($publicRoots as $publicRoot) {
            if (str_starts_with($normalize($directory).'/', $normalize(realpath($publicRoot) ?: $publicRoot).'/')) {
                throw new RuntimeException('Snapshot storage cannot use a public filesystem directory.');
            }
        }
        $cursor = rtrim($directory, '\\/');
        while (strlen($cursor) >= strlen($root)) {
            if (is_link($cursor)) {
                throw new RuntimeException('Snapshot storage cannot contain symbolic links.');
            }
            if (file_exists($cursor) && $normalize(realpath($cursor) ?: '') !== $normalize($cursor)) {
                throw new RuntimeException('Snapshot storage must use its canonical path.');
            }
            if ($normalize($cursor) === $normalize($root)) {
                break;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new RuntimeException('Invalid snapshot storage boundary.');
            }
            $cursor = $parent;
        }
        if (!class_exists(KraftDatabaseSnapshot::class, false)) {
            require_once base_path('updater/database.php');
        }
        $password = DB::connection()->getConfig('password');
        if ($password !== null && !is_string($password)) {
            throw new RuntimeException('The database password must be a string.');
        }

        return new KraftDatabaseSnapshot($state, $directory, $password);
    }
}
