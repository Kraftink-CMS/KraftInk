<?php

namespace App\Kraft\Extensions;

use App\Models\Notifications\CommunityNotice;
use App\Models\Setting;
use App\Models\Notifications\NotificationPreference;
use App\Models\ExtensionInstallation;
use App\Http\Middleware\OperationGuard;
use App\Kraft\Settings;
use App\Kraft\Updates\UpdatePreparation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Notifications\Notifications;
use Composer\Autoload\ClassLoader;
use Random\RandomException;
use JsonException;

class PackageUninstaller
{
    /**
     * @throws RandomException
     * @throws JsonException
     */
    public function stage(string $id): string
    {
        return OperationGuard::staging(function () use ($id): string {
            $extension = $this->candidate($id);
            $scope = app(PackagePaths::class)->scope($extension);
            $run = date('YmdHis').'-'.bin2hex(random_bytes(6));
            $root = storage_path('app/updates/'.$run);
            $this->assertNoLinks($root);
            try {
                File::ensureDirectoryExists($root.'/stage/'.$scope, 0700);
                $manifest = $extension['path'].'/manifest.json';
                $destination = $root.'/stage/'.$scope.'/manifest.json';
                if (!copy($manifest, $destination) || !chmod($destination, 0600)) {
                    $this->fail('update_private_storage');
                }
                $hash = hash_file('sha256', $destination);
                app(UpdatePreparation::class)->publish(
                    $run,
                    [
                        'version' => $extension['version'],
                        'files' => [$scope.'/manifest.json' => $hash],
                        'delete' => [],
                    ],
                    [
                        'id' => $id,
                        'name' => $extension['name'],
                        'type' => $extension['type'],
                        'path' => $scope,
                        'operation' => 'uninstall',
                        'previous_version' => $extension['version'],
                        'previous_manifest_sha256' => $hash,
                        'was_enabled' => false,
                        'uninstall_storage' => UninstallData::validate($extension)['storage'],
                    ],
                );

                return $run;
            } catch (Throwable $exception) {
                if (is_dir($root)) {
                    File::deleteDirectory($root);
                }
                throw $exception;
            }
        });
    }

    public function uninstall(string $id, string $version, string $run): void
    {
        if (
            !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $run) ||
            getenv('KRAFT_UNINSTALL_RUN') !== $run ||
            !filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOLEAN)
        ) {
            $this->fail('uninstall_worker_required');
        }
        $journal = storage_path('app/updates/'.$run.'/journal.json');
        $this->assertNoLinks($journal);
        $state = json_decode(File::get($journal), true, 64, JSON_THROW_ON_ERROR);
        if (
            ($state['state'] ?? null) !== 'migrating' ||
            empty($state['database_snapshot']) ||
            ($state['extension']['operation'] ?? null) !== 'uninstall' ||
            ($state['extension']['id'] ?? null) !== $id ||
            ($state['extension']['previous_version'] ?? null) !== $version
        ) {
            $this->fail('uninstall_worker_required');
        }
        $extension = $this->candidate($id);
        if (
            !version_compare($extension['version'], $version, '==') ||
            !hash_equals(
                $state['extension']['previous_manifest_sha256'],
                hash_file('sha256', $extension['path'].'/manifest.json'),
            )
        ) {
            $this->fail('retire_invalid');
        }
        $data = UninstallData::validate($extension);
        app(Notifications::class)->forget($id);
        NotificationPreference::query()
            ->where('type', 'like', $id.'.%')
            ->delete();
        foreach ($data['notices'] as $table) {
            if (Schema::hasTable($table)) {
                CommunityNotice::query()
                    ->whereIn('id', DB::table($table)->select('notice_id'))
                    ->delete();
            }
        }
        $migrations = $extension['path'].'/database/migrations';
        if (is_dir($migrations) && app('migrator')->repositoryExists()) {
            app(PrivateLibraries::class)->load($extension);
            if (!empty($extension['namespace'])) {
                foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                    $loader->addPsr4($extension['namespace'], $extension['path'].'/src', true);
                }
            }
            app('migrator')->reset([$migrations]);
        }
        if ($data['settings'] !== []) {
            $keys = Setting::query()
                ->pluck('key')
                ->filter(
                    fn ($key) => collect($data['settings'])->contains(fn ($prefix) => str_starts_with($key, $prefix)),
                );
            foreach ($keys->chunk(500) as $chunk) {
                Setting::query()->whereIn('key', $chunk->all())->delete();
            }
        }
        foreach (['jobs', 'failed_jobs'] as $table) {
            if (empty($extension['namespace']) || !Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)
                ->select(['id', 'payload'])
                ->orderBy('id')
                ->chunkById(100, function ($jobs) use ($table, $extension): void {
                    foreach ($jobs as $job) {
                        $payload = json_decode($job->payload, true);
                        $class = $payload['data']['commandName'] ?? ($payload['displayName'] ?? null);
                        if (is_string($class) && str_starts_with($class, $extension['namespace'])) {
                            DB::table($table)->where('id', $job->id)->delete();
                        }
                    }
                });
        }
        ExtensionInstallation::query()->where('id', $id)->delete();
        app(InstallationReceipts::class)->refresh();
        app(Settings::class)->refresh();
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        view()->getFinder()->flush();
    }

    /**
     * @throws RandomException
     */
    private function candidate(string $id): array
    {
        if (app(BuiltinExtensions::class)->contains($id)) {
            $this->fail('builtin_managed');
        }
        app(InstallationReceipts::class)->refresh();
        $manager = app(ExtensionManager::class);
        $extensions = $manager->all();
        $extension = $extensions[$id] ?? $this->fail('missing');
        if (
            !$extension['valid'] ||
            !$extension['installed'] ||
            !$extension['manifest_current'] ||
            !in_array($extension['type'], ['plugin', 'theme', 'game'], true)
        ) {
            $this->fail('retire_invalid');
        }
        $errors = app(ExtensionRequirements::class)->dependentErrors($id, $extensions, true);
        if ($extension['type'] === 'game' && app(GameRegistry::class)->activeId() === $id) {
            $errors[] = __('site.games.choose_another_before_removal');
        }
        if (
            ($extension['type'] !== 'game' && ($extension['enabled'] || ($extension['requested_enabled'] ?? false))) ||
            in_array($id, $manager->selectedThemes(), true)
        ) {
            $errors[] = __('system.extensions.retire_active');
        }
        if ($errors) {
            throw ValidationException::withMessages(['extension' => $errors]);
        }
        $this->assertNoLinks($extension['path']);
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($extension['path'], FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            ) as $file
        ) {
            if ($file->isLink() || (!$file->isFile() && !$file->isDir())) {
                $this->fail('update_boundary');
            }
        }

        return $extension;
    }

    private function assertNoLinks(string $path): void
    {
        for ($cursor = $path; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) {
                $this->fail('update_boundary');
            }
        }
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['extension' => __('system.extensions.'.$key)]);
    }
}
