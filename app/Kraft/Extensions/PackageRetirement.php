<?php

namespace App\Kraft\Extensions;

use App\Models\ExtensionInstallation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use Closure;
use App\Kraft\Games\GameRegistry;
use App\Kraft\System\OperationLock;
use Random\RandomException;

class PackageRetirement
{
    public function all(): array
    {
        $retired = [];
        foreach (app(InstallationReceipts::class)->all() as $id => $receipt) {
            if (empty($receipt['retired_uuid']) || app(BuiltinExtensions::class)->contains($id)) {
                continue;
            }
            try {
                $retired[$id] = $this->package($receipt);
            } catch (Throwable) {
                $retired[$id] = [
                    'id' => $id,
                    'name' => $id,
                    'type' => $receipt['type'],
                    'version' => $receipt['version'],
                    'installed' => true,
                    'enabled' => false,
                    'valid' => false,
                    'error' => __('system.extensions.retired_unavailable'),
                ];
            }
        }

        return $retired;
    }

    /**
     * @throws RandomException
     */
    public function retire(string $id): void
    {
        $this->locked(function () use ($id): void {
            if (app(BuiltinExtensions::class)->contains($id)) {
                $this->fail('builtin_managed');
            }
            app(InstallationReceipts::class)->refresh();
            $manager = app(ExtensionManager::class);
            $extensions = $manager->all();
            $extension = $extensions[$id] ?? $this->fail('missing');
            if (
                !$extension['valid'] ||
                ($extension['installed'] && !$extension['manifest_current']) ||
                !in_array($extension['type'], ['plugin', 'theme', 'game'], true)
            ) {
                $this->fail('retire_invalid');
            }
            $errors = app(ExtensionRequirements::class)->dependentErrors($id, $extensions, true);
            if ($extension['type'] === 'game' && app(GameRegistry::class)->activeId() === $id) {
                $errors[] = __('site.games.choose_another_before_removal');
            }
            if (
                ($extension['type'] !== 'game' &&
                    ($extension['enabled'] || ($extension['requested_enabled'] ?? false))) ||
                in_array($id, $manager->selectedThemes(), true)
            ) {
                $errors[] = __('system.extensions.retire_active');
            }
            if ($errors) {
                throw ValidationException::withMessages(['extension' => $errors]);
            }
            $source = $extension['path'];
            $this->assertNoLinks($source);
            $this->assertTree($source);
            if (!$extension['installed']) {
                if (ExtensionInstallation::query()->where('id', $id)->exists()) {
                    $this->fail('retire_invalid');
                }
                if (!File::deleteDirectory($source)) {
                    $this->fail('remove_files_failed');
                }
                $this->clearCaches();

                return;
            }
            $uuid = (string) Str::uuid();
            $directory = $this->privateDirectory($uuid, true);
            DB::transaction(function () use ($id, $uuid): void {
                $receipt = ExtensionInstallation::query()->where('id', $id)->lockForUpdate()->first();
                if (!$receipt || ($receipt->type !== 'game' && $receipt->enabled) || $receipt->retired_uuid) {
                    $this->fail('retire_active');
                }
                ExtensionInstallation::query()
                    ->where('id', $id)
                    ->update(['retired_uuid' => $uuid, 'enabled' => false, 'updated_at' => now()]);
            }, 3);
            app(InstallationReceipts::class)->refresh();
            if (!@rename($source, $directory.'/package')) {
                ExtensionInstallation::query()
                    ->where('id', $id)
                    ->where('retired_uuid', $uuid)
                    ->update(['retired_uuid' => null, 'updated_at' => now()]);
                app(InstallationReceipts::class)->refresh();
                $this->fail('retire_move_failed');
            }
            $this->clearCaches();
        });
    }

    /**
     * @throws RandomException
     */
    public function restore(string $id): void
    {
        $this->locked(function () use ($id): void {
            if (app(BuiltinExtensions::class)->contains($id)) {
                $this->fail('builtin_managed');
            }
            app(InstallationReceipts::class)->refresh();
            $receipt = app(InstallationReceipts::class)->all()[$id] ?? $this->fail('missing');
            if (empty($receipt['retired_uuid'])) {
                $this->fail('retire_invalid');
            }
            $extension = $this->package($receipt);
            $target = $this->runtimePath($extension['type'], $id, false);
            if ($extension['path'] !== $target) {
                if (file_exists($target) || is_link($target)) {
                    $this->fail('restore_occupied');
                }
                if (!@rename($extension['path'], $target)) {
                    $this->fail('retire_move_failed');
                }
            }
            DB::transaction(function () use ($id, $receipt): void {
                $current = ExtensionInstallation::query()->where('id', $id)->lockForUpdate()->first();
                if (!$current || $current->retired_uuid !== $receipt['retired_uuid'] || $current->enabled) {
                    $this->fail('retire_invalid');
                }
                ExtensionInstallation::query()
                    ->where('id', $id)
                    ->update(['retired_uuid' => null, 'enabled' => false, 'updated_at' => now()]);
            }, 3);
            app(InstallationReceipts::class)->refresh();
            $this->clearCaches();
        });
    }

    private function package(array $receipt): array
    {
        $directory = $this->privateDirectory($receipt['retired_uuid']);
        $path = is_dir($directory.'/package')
            ? $directory.'/package'
            : $this->runtimePath($receipt['type'], $receipt['id']);
        $this->assertTree($path);
        $manifest = app(Manifest::class)->read($path, $receipt['type'], $receipt['id']);
        if (
            !hash_equals($receipt['manifest_hash'], hash_file('sha256', $path.'/manifest.json')) ||
            !version_compare($receipt['version'], $manifest['version'], '==')
        ) {
            $this->fail('retired_unavailable');
        }

        return array_merge($manifest, [
            'path' => $path,
            'valid' => true,
            'installed' => true,
            'enabled' => false,
            'manifest_current' => true,
            'retired' => true,
        ]);
    }

    private function runtimePath(string $type, string $id, bool $exists = true): string
    {
        if (!in_array($type, ['plugin', 'theme', 'game'], true) || !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $id)) {
            $this->fail('retire_invalid');
        }
        $configured = config(
            match ($type) {
                'plugin' => 'kraft.extensions.installed.plugins',
                'theme' => 'kraft.extensions.installed.themes',
                'game' => 'kraft.extensions.installed.games',
            },
        );
        $this->assertNoLinks($configured);
        $root = realpath($configured);
        if (!$root || is_link($root)) {
            $this->fail('update_boundary');
        }
        $path = $root.DIRECTORY_SEPARATOR.$id;
        if (is_link($path) || ($exists && (!is_dir($path) || realpath($path) !== $path))) {
            $this->fail('update_boundary');
        }

        return $path;
    }

    private function privateDirectory(string $uuid, bool $create = false): string
    {
        if (!Str::isUuid($uuid)) {
            $this->fail('retired_unavailable');
        }
        $path = storage_path('app');
        $this->assertNoLinks($path);
        foreach (['private', 'extensions', 'retired', $uuid] as $part) {
            $path .= DIRECTORY_SEPARATOR.$part;
            if (is_link($path)) {
                $this->fail('update_boundary');
            }
            if ($create) {
                File::ensureDirectoryExists($path, 0700);
                if (!chmod($path, 0700)) {
                    $this->fail('update_private_storage');
                }
            }
        }
        if (!is_dir($path)) {
            $this->fail('retired_unavailable');
        }

        return $path;
    }

    private function assertTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            $this->fail('update_boundary');
        }
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            ) as $file
        ) {
            if ($file->isLink() || (!$file->isFile() && !$file->isDir())) {
                $this->fail('update_boundary');
            }
        }
    }

    private function assertNoLinks(string $path): void
    {
        $cursor = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        while ($cursor !== '' && $cursor !== '.') {
            if (is_link($cursor)) {
                $this->fail('update_boundary');
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }
    }

    private function locked(Closure $operation): void
    {
        $operationLease = app(OperationLock::class)->acquireExclusive();
        $locks = [];
        try {
            foreach (
                [
                    storage_path('app/updates/update.lock'),
                    rtrim(config('imports.directory'), '/\\').'/import.lock',
                    storage_path('app/extensions.lock'),
                ] as $path
            ) {
                $this->assertNoLinks($path);
                File::ensureDirectoryExists(dirname($path), 0700);
                if (is_link($path)) {
                    $this->fail('update_boundary');
                }
                $handle = fopen($path, 'c+');
                if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                    $this->fail('update_busy');
                }
                $locks[] = $handle;
            }
            $operation();
        } finally {
            foreach (array_reverse($locks) as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            $operationLease->release();
        }
    }

    private function clearCaches(): void
    {
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        view()->getFinder()->flush();
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['extension' => __('system.extensions.'.$key)]);
    }
}
