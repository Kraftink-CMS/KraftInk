<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    exit(1);
}
$journalPath = realpath($argv[1]);
if (!$journalPath || basename($journalPath) !== 'journal.json') {
    exit(2);
}
$directory = dirname($journalPath);
require $directory.'/database.php';
require $directory.'/files.php';
if (is_file($directory.'/builtins.php')) {
    require $directory.'/builtins.php';
}
if (is_file($directory.'/releases.php')) {
    require $directory.'/releases.php';
}
require $directory.'/operations.php';
$journal = @file_get_contents($journalPath);
if (!is_string($journal)) {
    fwrite(STDERR, "Cannot read the update journal.\n");
    exit(2);
}
try {
    $state = json_decode($journal, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fwrite(STDERR, "The update journal contains invalid JSON.\n");
    exit(2);
}
$root = realpath($state['root'] ?? '');
if (!$root || !is_array($state['operations'] ?? null)) {
    exit(2);
}
if (!is_array($state['protected_rules'] ?? null)) {
    fwrite(STDERR, "The independent update protection snapshot is missing.\n");
    exit(2);
}
try {
    KraftUpdateFiles::configure($state['protected_rules']);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}
$storage = $state['storage'] ?? $root.'/storage';
$operationPath = $storage.'/app/operations.lock';
$operationLock = KraftOperationGate::open($operationPath);
if (!flock($operationLock, LOCK_EX | LOCK_NB)) {
    fclose($operationLock);
    fwrite(STDERR, "Another website operation is active. Try again later.\n");
    exit(3);
}
KraftOperationGate::advance($operationPath);
$scope = isset($state['extension']) ? $state['extension']['path'] ?? '' : null;
$builtins = isset($state['manifest']['builtins'])
    ? KraftBuiltinPackages::inventory($state['manifest']['builtins'])
    : [];
$protected = [...$state['protected_paths'] ?? [], $storage];
if (($state['database_driver'] ?? 'sqlite') === 'sqlite' && !empty($state['database'])) {
    foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
        $protected[] = $state['database'].$suffix;
    }
}
if ($scope !== null) {
    KraftUpdateFiles::validate($scope.'/manifest.json', $scope);
    if (($state['extension']['id'] ?? '') !== basename($scope)) {
        throw new RuntimeException('The extension update identity is inconsistent.');
    }
}
$maintenance = $storage.'/framework/down';
$ownsMaintenance = function () use (&$state, $maintenance, $directory): bool {
    if (!is_file($maintenance)) {
        return false;
    }
    $details = json_decode(file_get_contents($maintenance), true);

    return is_array($details) && ($details['kraft_update'] ?? null) === ($state['id'] ?? basename($directory));
};
$lock = fopen(dirname($directory).'/update.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "An update is already running.\n");
    exit(3);
}
chmod($directory, 0700);
chmod($journalPath, 0600);
$importLock = null;
$hasImportLock = false;
$generationAdvanced = false;
$write = /** @throws JsonException */ function (string $status, int $progress) use (&$state, $journalPath): void {
    $state['state'] = $status;
    $state['progress'] = $progress;
    KraftUpdateFiles::privateWrite($journalPath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
};
$run = function (array $command, array $environment = []) use ($root): void {
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $environment ? array_merge(getenv(), $environment) : null,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run migration process.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $started = time();
    $exitCode = null;
    try {
        do {
            $output = substr($output.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]), -65536);
            $info = proc_get_status($process);
            if (!$info['running']) {
                $exitCode = $info['exitcode'];
                break;
            }
            if (time() - $started > 600) {
                proc_terminate($process);
                throw new RuntimeException('Migration timed out.');
            }
            usleep(50000);
        } while (true);
        $output = substr($output.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]), -65536);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
    if ($exitCode !== 0) {
        throw new RuntimeException('Command failed: '.substr($output, -4000));
    }
};
$bootstrapCachePaths = function () use ($root): array {
    $directory = $root;
    foreach (['bootstrap', 'cache'] as $part) {
        $directory .= '/'.$part;
        if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            throw new RuntimeException('The framework cache directory is unsafe.');
        }
        if (!is_dir($directory)) {
            return [];
        }
    }
    $paths = [];
    foreach (['config.php', 'services.php', 'packages.php', 'routes-v7.php', 'events.php'] as $name) {
        $path = $directory.'/'.$name;
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('A framework cache file is unsafe.');
        }
        if (is_file($path)) {
            $paths[] = $path;
        }
    }

    return $paths;
};
$clearBootstrapCache = function () use ($bootstrapCachePaths): void {
    foreach ($bootstrapCachePaths() as $path) {
        if (!unlink($path)) {
            throw new RuntimeException('Cannot invalidate a generated framework cache file.');
        }
    }
};
$restore = function () use (
    &$state,
    $directory,
    $root,
    $maintenance,
    $write,
    $ownsMaintenance,
    $scope,
    $builtins,
    $protected,
    $run,
    $clearBootstrapCache,
    $operationPath,
    &$generationAdvanced,
): void {
    $write('rolling_back', 5);
    foreach (array_reverse($state['operations']) as $operation) {
        $target = KraftUpdateFiles::target($root, $operation['path'], $scope, $builtins, $protected);
        if ($operation['existed']) {
            $backup = KraftUpdateFiles::target($directory.'/backup', $operation['path'], $scope, $builtins);
            if (
                !is_file($backup) ||
                (isset($operation['sha256']) && !hash_equals($operation['sha256'], hash_file('sha256', $backup)))
            ) {
                throw new RuntimeException('A rollback file backup is missing or corrupted.');
            }
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
                throw new RuntimeException('Cannot create a rollback directory.');
            }
            if (
                is_link($target.'.kraft-new') ||
                !copy($backup, $target.'.kraft-new') ||
                !rename($target.'.kraft-new', $target)
            ) {
                throw new RuntimeException('Cannot restore an original file.');
            }
            if (isset($operation['sha256']) && !hash_equals($operation['sha256'], hash_file('sha256', $target))) {
                throw new RuntimeException('An original file was not restored completely.');
            }
            if (isset($operation['mode']) && !chmod($target, $operation['mode'])) {
                throw new RuntimeException('Cannot restore original file permissions.');
            }
        } elseif (is_file($target) && !unlink($target)) {
            throw new RuntimeException('Cannot remove an added file.');
        }
    }
    if (!empty($state['database_snapshot'])) {
        new KraftDatabaseSnapshot($state, $directory)->restore();
    }
    foreach (array_reverse($state['uninstall_storage_operations'] ?? []) as $operation) {
        $id = $state['extension']['id'];
        $target = KraftUpdateFiles::uninstallTarget($state['storage'].'/app/private', $operation['path'], $id);
        $backup = KraftUpdateFiles::uninstallTarget($directory.'/uninstall-storage', $operation['path'], $id);
        if (file_exists($backup)) {
            if (file_exists($target) || KraftUpdateFiles::uninstallFingerprint($backup) !== $operation['fingerprint']) {
                throw new RuntimeException('Cannot restore uninstall storage safely.');
            }
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
                throw new RuntimeException('Cannot restore an uninstall storage directory.');
            }
            if (!rename($backup, $target)) {
                throw new RuntimeException('Cannot restore uninstall storage.');
            }
        } elseif (
            !file_exists($target) ||
            KraftUpdateFiles::uninstallFingerprint($target) !== $operation['fingerprint']
        ) {
            throw new RuntimeException('An uninstall storage backup is missing or corrupted.');
        }
    }
    if (is_file($root.'/artisan')) {
        $clearBootstrapCache();
        $run([PHP_BINARY, 'artisan', 'optimize:clear', '--no-interaction'], ['KRAFT_SAFE_MODE' => 'true']);
        $run([PHP_BINARY, 'artisan', 'queue:restart', '--no-interaction'], ['KRAFT_SAFE_MODE' => 'true']);
    } elseif (!empty($state['database_snapshot'])) {
        throw new RuntimeException('The restored application cannot invalidate its caches.');
    }
    KraftOperationGate::advance($operationPath);
    $generationAdvanced = true;
    $state['generation'] = KraftOperationGate::generation($operationPath);
    if (!empty($state['maintenance_created']) && $ownsMaintenance() && !unlink($maintenance)) {
        throw new RuntimeException('Cannot leave update maintenance mode.');
    }
    $write('rolled_back', 100);
};
$finished = false;
register_shutdown_function(function () use (&$finished, &$state, $restore, $write): void {
    if (!$finished && in_array($state['state'], ['backing_up', 'applying', 'migrating', 'verifying'], true)) {
        try {
            $state['error'] = error_get_last()['message'] ?? 'Update interrupted.';
            $restore();
        } catch (Throwable $error) {
            $state['error'] = $error->getMessage();
            $write('recovery_required', 0);
        }
    }
});
register_shutdown_function(function () use (&$generationAdvanced, $operationPath, &$state, $journalPath): void {
    if (!$generationAdvanced) {
        try {
            KraftOperationGate::advance($operationPath);
            $generationAdvanced = true;
            $state['generation'] = KraftOperationGate::generation($operationPath);
            KraftUpdateFiles::privateWrite($journalPath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            error_log('KraftInk could not publish the final operation generation.');
        }
    }
});
try {
    $imports = $state['imports_directory'] ?? $storage.'/app/private/imports';
    if (!is_dir($imports) && !mkdir($imports, 0700, true) && !is_dir($imports)) {
        throw new RuntimeException('Cannot acquire the import coordination lock.');
    }
    $importLock = fopen($imports.'/import.lock', 'c+');
    if (!$importLock || !flock($importLock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('An import is already running.');
    }
    $hasImportLock = true;
    if (($argv[2] ?? '') === 'recover') {
        if (
            !in_array(
                $state['state'],
                ['backing_up', 'applying', 'migrating', 'verifying', 'recovery_required', 'rolling_back'],
                true,
            )
        ) {
            throw new RuntimeException('This update does not need recovery.');
        }
        $restore();
        $finished = true;
        exit(0);
    }
    if ($state['state'] !== 'staged') {
        throw new RuntimeException('Update cannot start in this state.');
    }
    if (is_file($maintenance)) {
        throw new RuntimeException('Existing maintenance mode must be resolved first.');
    }
    if (
        $scope !== null &&
        (!is_file($root.'/'.$scope.'/manifest.json') ||
            !hash_equals(
                $state['extension']['previous_manifest_sha256'],
                hash_file('sha256', $root.'/'.$scope.'/manifest.json'),
            ))
    ) {
        throw new RuntimeException('The installed extension changed after update preparation.');
    }
    if ($scope === null) {
        if (array_key_exists('build', $state['manifest']) && !class_exists(KraftReleaseBuild::class, false)) {
            throw new RuntimeException('The independent release provenance validator is missing.');
        }
        if (class_exists(KraftReleaseBuild::class, false)) {
            if (is_link($root.'/manifest.json')) {
                throw new RuntimeException('The installed CMS manifest must not be a symbolic link.');
            }
            $configuration = is_file($root.'/manifest.json')
                ? KraftReleaseBuild::configuration(file_get_contents($root.'/manifest.json'))
                : [];
            KraftReleaseBuild::validate($state['manifest'], $configuration);
            $source = $directory.'/stage/manifest.json';
            if (!isset($state['manifest']['files']['manifest.json']) || !is_file($source) || is_link($source)) {
                throw new RuntimeException('The staged CMS release manifest is missing.');
            }
            KraftReleaseBuild::verifyConfiguration($state['manifest'], file_get_contents($source));
        }
    }
    $createOnly = $state['manifest']['create_only'] ?? [];
    if (!is_array($createOnly) || !array_is_list($createOnly) || count($createOnly) > 50000) {
        throw new RuntimeException('Invalid create-only update files.');
    }
    $pendingSeeds = [];
    foreach ($createOnly as $path) {
        if (!is_string($path) || !isset($state['manifest']['files'][$path])) {
            throw new RuntimeException('A create-only update file is absent from the update inventory.');
        }
        KraftUpdateFiles::validate($path, $scope, $builtins, true);
        if (KraftUpdateFiles::occupied($root, $path)) {
            unset($state['manifest']['files'][$path]);
        } else {
            $pendingSeeds[] = $path;
        }
    }
    $state['manifest']['create_only'] = $pendingSeeds;
    $state['manifest']['delete'] = array_values(
        array_unique(
            array_merge(
                $state['manifest']['delete'] ?? [],
                KraftUpdateFiles::synchronize(
                    $root,
                    $state['sync_paths'] ?? [],
                    $state['manifest']['files'],
                    $state['preserve_paths'] ?? [],
                    $scope,
                    $builtins,
                    $protected,
                ),
            ),
        ),
    );
    $write('staged', 0);
    new KraftDatabaseSnapshot($state, $directory)->preflight();
    $paths = array_unique(array_merge(array_keys($state['manifest']['files']), $state['manifest']['delete'] ?? []));
    if ($paths === []) {
        throw new RuntimeException('The staged update has no file operations.');
    }
    foreach ($paths as $path) {
        $target = KraftUpdateFiles::target($root, $path, $scope, $builtins, $protected);
        $writable = dirname($target);
        while (!is_dir($writable)) {
            $writable = dirname($writable);
        }
        if (!is_writable($writable)) {
            throw new RuntimeException('An update directory is not writable.');
        }
        if (isset($state['manifest']['files'][$path])) {
            $source = KraftUpdateFiles::target($directory.'/stage', $path, $scope, $builtins);
            if (!is_file($source) || !hash_equals($state['manifest']['files'][$path], hash_file('sha256', $source))) {
                throw new RuntimeException('A staged file changed before execution.');
            }
        }
    }
    $bootstrapCachePaths();
    $state['maintenance_created'] = true;
    $write('backing_up', 5);
    $maintenanceHandle = fopen($maintenance, 'x');
    if (!$maintenanceHandle) {
        throw new RuntimeException('Cannot enter maintenance mode.');
    }
    $maintenanceContents = json_encode(
        ['time' => time(), 'retry' => 10, 'kraft_update' => $state['id'] ?? basename($directory)],
        JSON_THROW_ON_ERROR,
    );
    $maintenanceWritten = fwrite($maintenanceHandle, $maintenanceContents);
    fclose($maintenanceHandle);
    if ($maintenanceWritten !== strlen($maintenanceContents)) {
        unlink($maintenance);
        throw new RuntimeException('Cannot persist maintenance mode.');
    }
    new KraftDatabaseSnapshot($state, $directory)->backup();
    $state['database_snapshot'] = true;
    if (($state['extension']['operation'] ?? null) === 'uninstall') {
        foreach ($state['extension']['uninstall_storage'] ?? [] as $path) {
            $source = KraftUpdateFiles::uninstallTarget($storage.'/app/private', $path, $state['extension']['id']);
            if (!file_exists($source)) {
                continue;
            }
            $backup = KraftUpdateFiles::uninstallTarget(
                $directory.'/uninstall-storage',
                $path,
                $state['extension']['id'],
            );
            if (file_exists($backup)) {
                throw new RuntimeException('Uninstall storage backup is already occupied.');
            }
            $state['uninstall_storage_operations'][] = [
                'path' => $path,
                'fingerprint' => KraftUpdateFiles::uninstallFingerprint($source),
            ];
            $write('backing_up', 10);
            if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0700, true)) {
                throw new RuntimeException('Cannot prepare an uninstall storage backup.');
            }
            if (!rename($source, $backup)) {
                throw new RuntimeException('Cannot back up uninstall storage.');
            }
        }
    }
    foreach ($paths as $path) {
        $target = KraftUpdateFiles::target($root, $path, $scope, $builtins, $protected);
        $backup = $directory.'/backup/'.$path;
        $existed = is_file($target);
        $hash = null;
        if ($existed) {
            if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0700, true)) {
                throw new RuntimeException('Cannot create a private file backup directory.');
            }
            $hash = hash_file('sha256', $target);
            if (!copy($target, $backup) || !chmod($backup, 0600) || !hash_equals($hash, hash_file('sha256', $backup))) {
                throw new RuntimeException('Cannot back up an original file completely.');
            }
        }
        $state['operations'][] = [
            'path' => $path,
            'existed' => $existed,
            'sha256' => $hash,
            'mode' => $existed ? fileperms($target) & 0777 : 0644,
        ];
    }
    if (!is_dir($directory.'/backup') && !mkdir($directory.'/backup', 0700)) {
        throw new RuntimeException('Cannot create private rollback storage.');
    }
    $write('applying', 20);
    $count = count($paths);
    $index = 0;
    foreach ($paths as $path) {
        $target = KraftUpdateFiles::target($root, $path, $scope, $builtins, $protected);
        if (isset($state['manifest']['files'][$path])) {
            $source = KraftUpdateFiles::target($directory.'/stage', $path, $scope, $builtins);
            if (!hash_equals($state['manifest']['files'][$path], hash_file('sha256', $source))) {
                throw new RuntimeException('A staged file changed during execution.');
            }
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
                throw new RuntimeException('Cannot create an update directory.');
            }
            if (
                is_link($target.'.kraft-new') ||
                !copy($source, $target.'.kraft-new') ||
                !rename($target.'.kraft-new', $target)
            ) {
                throw new RuntimeException('Cannot replace an update file.');
            }
            $operation = $state['operations'][$index];
            if (!chmod($target, $operation['mode'])) {
                throw new RuntimeException('Cannot preserve update file permissions.');
            }
        } elseif (is_file($target) && !unlink($target)) {
            throw new RuntimeException('Cannot delete an obsolete file.');
        }
        $progress = 20 + (int) ((++$index / $count) * 45);
        if ($progress !== $state['progress']) {
            $write('applying', $progress);
        }
    }
    $write('migrating', 70);
    $migrationEnvironment = ['KRAFT_SAFE_MODE' => 'true'];
    $clearBootstrapCache();
    $run([PHP_BINARY, 'artisan', 'optimize:clear', '--no-interaction'], $migrationEnvironment);
    if (($state['extension']['operation'] ?? null) === 'uninstall') {
        $run(
            [
                PHP_BINARY,
                'artisan',
                'kraft:extension-uninstall',
                $state['extension']['id'],
                $state['manifest']['version'],
                $state['id'],
            ],
            [...$migrationEnvironment, 'KRAFT_UNINSTALL_RUN' => $state['id']],
        );
    } elseif ($scope === null) {
        $run(
            [
                PHP_BINARY,
                'artisan',
                'migrate',
                '--path='.$root.'/database/migrations',
                '--realpath',
                '--force',
                '--no-interaction',
            ],
            $migrationEnvironment,
        );
    } elseif (is_dir($root.'/'.$scope.'/database/migrations')) {
        $run(
            [
                PHP_BINARY,
                'artisan',
                'kraft:extension-migrate',
                $state['extension']['id'],
                $state['manifest']['version'],
                $state['id'],
                '--no-interaction',
            ],
            [...$migrationEnvironment, 'KRAFT_EXTENSION_MIGRATION_RUN' => $state['id']],
        );
    }
    if ($scope === null && array_key_exists('builtins', $state['manifest'])) {
        $run(
            [
                PHP_BINARY,
                'artisan',
                'kraft:builtins-sync',
                '--inventory='.base64_encode(json_encode($builtins, JSON_THROW_ON_ERROR)),
                '--no-interaction',
            ],
            [...$migrationEnvironment, 'KRAFT_BUILTIN_SYNC_RUN' => $state['id']],
        );
    }
    $write('verifying', 90);
    if ($scope === null) {
        $run([
            PHP_BINARY,
            'artisan',
            'kraft:health',
            '--expected-version='.$state['manifest']['version'],
            '--no-interaction',
        ]);
    } elseif (($state['extension']['operation'] ?? null) === 'uninstall') {
        $run([PHP_BINARY, 'artisan', 'kraft:health', '--no-interaction'], ['KRAFT_SAFE_MODE' => 'true']);
    } else {
        $health = [
            PHP_BINARY,
            'artisan',
            'kraft:extension-health',
            $state['extension']['id'],
            $state['manifest']['version'],
            '--no-interaction',
        ];
        $health[] = '--operation-run='.$state['id'];
        if (!empty($state['extension']['enable_after_install'])) {
            $health[] = '--enable-after-install';
        }
        if (!empty($state['extension']['select_theme'])) {
            $health[] = '--select-theme';
        }
        $run($health, ['KRAFT_SAFE_MODE' => 'false', 'KRAFT_PROBE_EXTENSION' => $state['extension']['id']]);
    }
    $run([PHP_BINARY, 'artisan', 'optimize:clear', '--no-interaction'], ['KRAFT_SAFE_MODE' => 'true']);
    $run([PHP_BINARY, 'artisan', 'queue:restart', '--no-interaction']);
    if (!$ownsMaintenance() || !unlink($maintenance)) {
        throw new RuntimeException('Cannot leave maintenance mode.');
    }
    KraftOperationGate::advance($operationPath);
    $generationAdvanced = true;
    $state['generation'] = KraftOperationGate::generation($operationPath);
    $write('completed', 100);
    $finished = true;
} catch (Throwable $error) {
    $state['error'] = $error->getMessage();
    try {
        if (!$hasImportLock) {
            $write($state['state'] === 'staged' ? 'failed' : 'recovery_required', 0);
        } elseif (in_array($state['state'], ['backing_up', 'applying', 'migrating', 'verifying'], true)) {
            $restore();
        } elseif ($state['state'] === 'staged') {
            $write('failed', 0);
        } elseif (in_array($state['state'], ['rolling_back', 'recovery_required'], true)) {
            $write('recovery_required', 0);
        }
    } catch (Throwable $restoreError) {
        $state['error'] .= ' | Rollback: '.$restoreError->getMessage();
        $write('recovery_required', 0);
    }
    $finished = true;
    fwrite(STDERR, $state['error']."\n");
    exit(1);
} finally {
    if (is_resource($importLock)) {
        flock($importLock, LOCK_UN);
        fclose($importLock);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    if (!$generationAdvanced) {
        KraftOperationGate::advance($operationPath);
        $generationAdvanced = true;
    }
    flock($operationLock, LOCK_UN);
    fclose($operationLock);
}
