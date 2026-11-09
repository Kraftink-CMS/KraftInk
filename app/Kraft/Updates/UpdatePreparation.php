<?php

namespace App\Kraft\Updates;

use Illuminate\Support\Facades\File;
use RuntimeException;
use KraftDatabaseSnapshot;
use KraftUpdateFiles;
use KraftBuiltinPackages;
use KraftManifest;
use App\Kraft\System\DatabaseSnapshots;
use Random\RandomException;
use JsonException;

class UpdatePreparation
{
    /**
     * @throws RandomException
     * @throws JsonException
     */
    public function publish(string $id, array $manifest, ?array $extension = null, array $synchronize = []): void
    {
        if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $id)) {
            throw new RuntimeException('Invalid update identifier.');
        }
        if (!class_exists(KraftDatabaseSnapshot::class, false)) {
            require_once base_path('updater/database.php');
        }
        if (!class_exists(KraftUpdateFiles::class, false)) {
            require_once base_path('updater/files.php');
        }
        if (!class_exists(KraftBuiltinPackages::class, false)) {
            require_once base_path('updater/builtins.php');
        }
        $protection = config('manifest.update.protected');
        if (!is_array($protection)) {
            throw new RuntimeException('The installed update protection configuration is invalid.');
        }
        KraftUpdateFiles::configure($protection);
        $root = storage_path('app/updates/'.$id);
        if ($extension === null && is_file($root.'/stage/manifest.json')) {
            if (!class_exists(KraftManifest::class, false)) {
                require_once base_path('updater/manifest.php');
            }
            $target = KraftManifest::read($root.'/stage', 'cms', 'kraftink');
            KraftUpdateFiles::configure(array_values(array_unique(
                array_merge(
                    KraftUpdateFiles::protectionRules($protection),
                    KraftUpdateFiles::protectionRules($target['update']['protected']),
                ),
                SORT_REGULAR,
            )));
        }
        File::ensureDirectoryExists($root, 0700);
        if (is_link($root) || !chmod($root, 0700)) {
            throw new RuntimeException('Cannot protect private update storage.');
        }
        /** @var DatabaseSnapshots $snapshots */
        $snapshots = app(DatabaseSnapshots::class);
        $databaseState = $snapshots->describe();
        $protected = [storage_path(), config('imports.directory')];
        foreach (config('filesystems.disks', []) as $disk) {
            if (($disk['driver'] ?? null) === 'local') {
                $protected[] = $disk['root'] ?? null;
            }
        }
        if ($extension === null) {
            foreach (['plugins', 'themes', 'games'] as $kind) {
                $protected[] = config('kraft.extensions.installed.'.$kind);
            }
        }
        if ($databaseState['database_driver'] === 'sqlite') {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                $protected[] = $databaseState['database'].$suffix;
            }
        }
        $protected = array_values(
            array_unique(array_filter($protected, fn ($path) => is_string($path) && $path !== '')),
        );
        $scope = $extension['path'] ?? null;
        $builtins = KraftBuiltinPackages::inventory($manifest['builtins'] ?? []);
        $required =
            $scope === null
                ? [
                    'manifest.json',
                    ...array_map(fn ($path) => $path.'/manifest.json', KraftBuiltinPackages::scopes($builtins)),
                ]
                : [$scope.'/manifest.json'];
        $requiredFiles = array_intersect($required, array_keys($manifest['files']));
        $preserve = KraftUpdateFiles::preservation(
            array_key_exists('preserve', $manifest) ? $manifest['preserve'] : [],
            $scope === null ? $required : ['manifest.json'],
        );
        if ($scope !== null) {
            $preserve = array_map(fn ($path) => $scope.'/'.$path, $preserve);
        } else {
            foreach (KraftBuiltinPackages::manifests($root.'/stage', $builtins) as $package) {
                $preserve = array_merge(
                    $preserve,
                    array_map(
                        fn ($path) => $package['scope'].'/'.$path,
                        KraftUpdateFiles::preservation($package['update']['preserve'] ?? [], ['manifest.json']),
                    ),
                );
            }
        }
        $createOnly = [];
        $retained = [];
        foreach (array_merge(array_keys($manifest['files']), $manifest['delete'] ?? []) as $path) {
            KraftUpdateFiles::validate($path, $scope, $builtins, true);
            $packageScope = $scope ?? KraftBuiltinPackages::scope($path, $builtins);
            $relative = $packageScope === null ? $path : substr($path, strlen($packageScope) + 1);
            if (
                KraftUpdateFiles::preserved($relative, $packageScope !== null) ||
                KraftUpdateFiles::local(base_path(), $path, $protected)
            ) {
                unset($manifest['files'][$path]);

                continue;
            }
            if (KraftUpdateFiles::matches($path, $preserve)) {
                if (!isset($manifest['files'][$path]) || KraftUpdateFiles::occupied(base_path(), $path)) {
                    unset($manifest['files'][$path]);

                    continue;
                }
                $createOnly[$path] = true;
            }
            KraftUpdateFiles::target(base_path(), $path, $scope, $builtins, $protected);
            $retained[$path] = true;
        }
        $manifest['delete'] = array_values(
            array_filter($manifest['delete'] ?? [], fn ($path) => isset($retained[$path])),
        );
        $manifest['create_only'] = array_values(
            array_intersect(array_keys($createOnly), array_keys($manifest['files'])),
        );
        $synchronize =
            $scope === null
                ? KraftUpdateFiles::synchronization([
                    ...$manifest['sync'] ?? [],
                    ...KraftBuiltinPackages::scopes($builtins),
                ])
                : KraftUpdateFiles::synchronization($synchronize);
        $manifest['delete'] = array_values(array_unique(array_merge(
            $manifest['delete'],
            KraftUpdateFiles::synchronize(
                base_path(),
                $synchronize,
                $manifest['files'],
                $preserve,
                $scope,
                $builtins,
                $protected,
            ),
        )));
        if ($manifest['files'] === [] || array_diff($requiredFiles, array_keys($manifest['files'])) !== []) {
            throw new RuntimeException('Local exclusions leave an incomplete update plan.');
        }
        $token = bin2hex(random_bytes(32));
        if ($extension !== null && app()->bound('session') && session()->isStarted()) {
            $reserved = session('updates.installation_tokens.'.$extension['id']);
            if (is_string($reserved) && preg_match('/^[a-f0-9]{64}$/D', $reserved)) {
                $token = $reserved;
            }
        }
        $journal = [
            'id' => $id,
            'state' => 'staged',
            'progress' => 0,
            'version' => $manifest['version'],
            'root' => base_path(),
            'storage' => storage_path(),
            'imports_directory' => config('imports.directory'),
            'protected_paths' => $protected,
            'protected_rules' => KraftUpdateFiles::protection(),
            'sync_paths' => $synchronize,
            'preserve_paths' => $preserve,
            ...$databaseState,
            'progress_token_hash' => hash('sha256', $token),
            'manifest' => $manifest,
            'created_at' => gmdate(DATE_ATOM),
            'operations' => [],
        ];
        if ($extension !== null) {
            $journal['extension'] = $extension;
        }
        $snapshots->make($root, $databaseState)->preflight();
        $workers = ['worker.php', 'database.php', 'files.php', 'builtins.php', 'manifest.php'];
        if ($databaseState['database_driver'] === 'pgsql') {
            $workers[] = 'postgres.php';
        }
        if ($extension === null && (isset($manifest['build']) || is_file(base_path('updater/releases.php')))) {
            $workers[] = 'releases.php';
        }
        foreach ($workers as $file) {
            if (!copy(base_path('updater/'.$file), $root.'/'.$file) || !chmod($root.'/'.$file, 0600)) {
                throw new RuntimeException('Cannot prepare the independent update process.');
            }
        }
        if (
            !copy(base_path('bootstrap/operations.php'), $root.'/operations.php') ||
            !chmod($root.'/operations.php', 0600)
        ) {
            throw new RuntimeException('Cannot prepare independent operation coordination.');
        }
        KraftUpdateFiles::privateWrite(
            $root.'/journal.json',
            json_encode($journal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        if (app()->bound('session') && session()->isStarted()) {
            session()->put('updates.tokens.'.$id, $token);
        }
    }
}
