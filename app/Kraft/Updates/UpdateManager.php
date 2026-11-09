<?php

namespace App\Kraft\Updates;

use App\Kraft\SigningKeys;
use App\Http\Middleware\OperationGuard;
use App\Kraft\Migration\ImportManager;
use App\Kraft\System\DatabaseSnapshots;
use App\Exceptions\OperationBusy;
use App\Kraft\System\OperationLock;
use App\Kraft\System\PhpBinary;
use App\Kraft\System\ProcessEnvironment;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;
use KraftBuiltinPackages;
use KraftUpdateFiles;
use App\Kraft\Extensions\DependencyConstraints;
use App\Kraft\Extensions\Manifest;
use JsonException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class UpdateManager
{
    public function stage(string $archive, ?string $expectedVersion = null): string
    {
        if (app(ImportManager::class)->isRunning()) {
            throw new RuntimeException('An import is already running.');
        }
        $key = base64_decode(app(SigningKeys::class)->publicKey(config('manifest.update.signing_key')), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('A trusted Ed25519 update key must be configured before updating.');
        }
        if (!is_file($archive) || filesize($archive) > config('updates.max_archive_size', 536870912)) {
            throw new RuntimeException('The update archive is missing or oversized.');
        }
        foreach (
            [
                'KraftDatabaseSnapshot' => 'database.php',
                'KraftUpdateFiles' => 'files.php',
                'KraftBuiltinPackages' => 'builtins.php',
            ] as $class => $file
        ) {
            if (!class_exists($class, false)) {
                require_once base_path('updater/'.$file);
            }
        }
        KraftUpdateFiles::configure(config('manifest.update.protected'));
        $id = date('YmdHis').'-'.bin2hex(random_bytes(6));
        $root = storage_path('app/updates/'.$id);
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($archive) !== true) {
                throw new RuntimeException('Invalid update archive.');
            }
            $opened = true;
            $metadata = $zip->statName('release.json');
            $signatureMetadata = $zip->statName('release.sig');
            if (!$metadata || $metadata['size'] > 8388608 || !$signatureMetadata || $signatureMetadata['size'] > 1024) {
                throw new RuntimeException('Missing or oversized update metadata.');
            }
            $raw = $zip->getFromName('release.json');
            $signature = base64_decode(trim((string) $zip->getFromName('release.sig')), true);
            if (
                !is_string($raw) ||
                !$signature ||
                strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
                !sodium_crypto_sign_verify_detached($signature, $raw, $key)
            ) {
                throw new RuntimeException('Update signature rejected.');
            }
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            $this->manifest($manifest, $expectedVersion);
            $builtins = KraftBuiltinPackages::inventory($manifest['builtins'] ?? []);
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = $entry['name'];
                if (isset($seen[$name])) {
                    throw new RuntimeException('Duplicate ZIP entries are forbidden.');
                }
                $seen[$name] = true;
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Symbolic links are forbidden in update archives.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (
                    $name !== 'release.json' &&
                    $name !== 'release.sig' &&
                    (!str_starts_with($name, 'files/') || !array_key_exists(substr($name, 6), $manifest['files']))
                ) {
                    throw new RuntimeException('An archive file is absent from the signed manifest.');
                }
            }
            File::ensureDirectoryExists(storage_path('app/updates'), 0700);
            if (is_link(storage_path('app/updates')) || !chmod(storage_path('app/updates'), 0700)) {
                throw new RuntimeException('Cannot protect private update storage.');
            }
            File::ensureDirectoryExists($root.'/stage', 0700);
            if (!chmod($root, 0700) || !chmod($root.'/stage', 0700)) {
                throw new RuntimeException('Cannot protect private staging storage.');
            }
            $size = 0;
            foreach ($manifest['files'] as $path => $hash) {
                KraftUpdateFiles::validate($path, null, $builtins, true);
                $entry = $zip->statName('files/'.$path);
                if (!$entry || !is_int($entry['size']) || ($size += $entry['size']) > 1073741824) {
                    throw new RuntimeException('Missing or oversized update file.');
                }
                $destination = $root.'/stage/'.$path;
                File::ensureDirectoryExists(dirname($destination), 0700);
                $input = $zip->getStream('files/'.$path);
                $output = fopen($destination, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new RuntimeException('Cannot extract an update file.');
                }
                $digest = hash_init('sha256');
                $written = 0;
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 65536);
                        if (
                            $chunk === false ||
                            ($written += strlen($chunk)) > $entry['size'] ||
                            fwrite($output, $chunk) !== strlen($chunk)
                        ) {
                            throw new RuntimeException('Incomplete update extraction.');
                        }
                        hash_update($digest, $chunk);
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($written !== $entry['size'] || !hash_equals($hash, hash_final($digest))) {
                    throw new RuntimeException('Update checksum rejected.');
                }
            }
            foreach ($manifest['delete'] ?? [] as $path) {
                KraftUpdateFiles::validate($path, null, $builtins, true);
            }
            $this->configuration($manifest, $root.'/stage');
            foreach (KraftBuiltinPackages::manifests($root.'/stage', $builtins) as $packageId => $extension) {
                $next = app(Manifest::class)->read($extension['path'], $extension['type'], $packageId);
                $requirements = app(DependencyConstraints::class)->requirements($next);
                if (
                    !Semver::satisfies($manifest['version'], $requirements['cms']) ||
                    !Semver::satisfies(PHP_VERSION, $requirements['php'])
                ) {
                    throw new RuntimeException('A bundled package is incompatible with the target release.');
                }
            }
            $this->manifest($manifest, $expectedVersion);
            app(UpdatePreparation::class)->publish($id, $manifest);
        } catch (Throwable $exception) {
            if (is_dir($root) && !is_link($root)) {
                File::deleteDirectory($root);
            }
            throw $exception;
        } finally {
            if ($opened) {
                $zip->close();
            }
        }

        return $id;
    }

    public function run(string $id, bool $recover = false): Process
    {
        ignore_user_abort(true);
        set_time_limit(0);

        return OperationGuard::staging(fn () => $this->executeRun($id, $recover));
    }

    /**
     * @throws JsonException
     * @throws FileNotFoundException
     */
    private function executeRun(string $id, bool $recover): Process
    {
        $root = $this->path($id);
        $state = json_decode(File::get($root.'/journal.json'), true, 64, JSON_THROW_ON_ERROR);
        if (!$recover && !isset($state['extension'])) {
            $this->manifest($state['manifest'] ?? null, $state['version'] ?? null);
            $this->configuration($state['manifest'], $root.'/stage');
        }
        /** @var DatabaseSnapshots $snapshots */
        $snapshots = app(DatabaseSnapshots::class);
        $snapshots->make($root, $state);
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException(__('system.updates.active_transaction'));
        }
        $connection = DB::connection()->getConfig();
        $binary = app(PhpBinary::class)->path();
        $literal = fn ($value) => '"'.(string) ($value ?? '').'"';
        $environment = [
            'APP_ENV' => 'production',
            'LARAVEL_STORAGE_PATH' => storage_path(),
            'DB_CONNECTION' => $state['database_driver'],
            'DB_URL' => $literal(''),
            'DB_DATABASE' => $literal($connection['database'] ?? ''),
            'DB_HOST' => $literal($connection['host'] ?? ''),
            'DB_PORT' => $literal($connection['port'] ?? ''),
            'DB_USERNAME' => $literal($connection['username'] ?? ''),
            'DB_PASSWORD' => $literal($connection['password'] ?? ''),
            'KRAFT_UPDATE_DB_PASSWORD' => (string) ($connection['password'] ?? ''),
            ...($state['database_driver'] === 'pgsql'
                ? ['DB_SSLMODE' => $literal($connection['sslmode'] ?? 'prefer')]
                : []),
            ...app(ProcessEnvironment::class)->variables(),
        ];
        $process = new Process(
            [$binary, $root.'/worker.php', $root.'/journal.json', $recover ? 'recover' : 'apply'],
            base_path(),
            $environment,
        );
        $process->setTimeout(null);
        OperationGuard::beforeWorker(isset($state['extension']));
        if (!app(OperationLock::class)->idle()) {
            throw new OperationBusy();
        }
        DB::disconnect();
        try {
            $process->run();
        } finally {
            DB::purge();
            OperationGuard::workerFinished(
                json_decode(File::get($root.'/journal.json'), true, 64, JSON_THROW_ON_ERROR),
            );
        }

        return $process;
    }

    /**
     * @throws JsonException
     * @throws FileNotFoundException
     */
    public function status(string $id): array
    {
        $data = json_decode(File::get($this->path($id).'/journal.json'), true, 64, JSON_THROW_ON_ERROR);
        $status = array_intersect_key($data, array_flip(['id', 'state', 'progress', 'version', 'created_at']));
        if (isset($data['extension']['name'])) {
            $status['subject'] = __($data['extension']['name']).' · '.$status['version'];
            $status['extension_id'] = $data['extension']['id'];
            $status['extension_type'] = $data['extension']['type'] ?? null;
            $status['operation'] = $data['extension']['operation'] ?? 'update';
        }
        if (!empty($data['error'])) {
            $status['error'] = __('system.updates.failure');
        }

        return $status;
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $id)) {
            throw new RuntimeException('Invalid update identifier.');
        }
        $path = storage_path('app/updates/'.$id);
        if (is_link($path) || !is_file($path.'/journal.json')) {
            throw new RuntimeException('Unknown update run.');
        }

        return $path;
    }

    private function manifest(mixed $manifest, ?string $expectedVersion): void
    {
        $version = $manifest['version'] ?? null;
        if (
            !is_array($manifest) ||
            !is_string($version) ||
            strlen($version) > 40 ||
            ReleaseVersion::channel($version) === null ||
            version_compare($version, config('manifest.version'), '<=') ||
            ($expectedVersion !== null && $version !== $expectedVersion) ||
            !is_array($manifest['files'] ?? null) ||
            $manifest['files'] === [] ||
            count($manifest['files']) > 50000 ||
            ($manifest['schema_version'] ?? null) !== 1
        ) {
            throw new RuntimeException('Invalid, unexpected or non-upgrade release manifest.');
        }
        app(UpdateChannel::class)->validate($manifest);
        ReleaseBuild::validate($manifest);
        if (!is_array($manifest['requirements'] ?? null)) {
            throw new RuntimeException('Invalid release requirements.');
        }
        foreach (['php' => PHP_VERSION, 'cms' => config('manifest.version')] as $requirement => $current) {
            $constraint = $manifest['requirements'][$requirement] ?? null;
            if (!is_string($constraint) || !Semver::satisfies($current, $constraint)) {
                throw new RuntimeException('The release requirements are not satisfied.');
            }
        }
        $paths = [];
        if (!class_exists(KraftUpdateFiles::class, false)) {
            require_once base_path('updater/files.php');
        }
        if (!class_exists(KraftBuiltinPackages::class, false)) {
            require_once base_path('updater/builtins.php');
        }
        $builtins = KraftBuiltinPackages::inventory($manifest['builtins'] ?? []);
        foreach (
            KraftUpdateFiles::synchronization(array_key_exists('sync', $manifest) ? $manifest['sync'] : []) as $directory
        ) {
            KraftUpdateFiles::validate($directory, null, $builtins, true, true);
        }
        KraftUpdateFiles::preservation(array_key_exists('preserve', $manifest) ? $manifest['preserve'] : [], [
            'manifest.json',
            ...array_map(fn ($scope) => $scope.'/manifest.json', KraftBuiltinPackages::scopes($builtins)),
        ]);
        foreach (KraftBuiltinPackages::scopes($builtins) as $scope) {
            if (!isset($manifest['files'][$scope.'/manifest.json'])) {
                throw new RuntimeException('A declared builtin package is absent from the release.');
            }
        }
        if (
            isset($manifest['delete']) &&
            (!is_array($manifest['delete']) ||
                !array_is_list($manifest['delete']) ||
                count($manifest['delete']) > 50000)
        ) {
            throw new RuntimeException('Invalid release deletion list.');
        }
        foreach (array_merge(array_keys($manifest['files']), $manifest['delete'] ?? []) as $path) {
            if (!is_string($path)) {
                throw new RuntimeException('Invalid release path.');
            }
            KraftUpdateFiles::validate($path, null, $builtins, true);
            $canonical = strtolower($path);
            if (isset($paths[$canonical])) {
                throw new RuntimeException('Conflicting or case-ambiguous release paths.');
            }
            $paths[$canonical] = true;
        }
        foreach ($manifest['files'] as $hash) {
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('Invalid file checksum.');
            }
        }
    }

    /**
     * @throws FileNotFoundException
     */
    private function configuration(array $manifest, string $stage): void
    {
        $path = $stage.'/manifest.json';
        if (!isset($manifest['files']['manifest.json']) || !is_file($path) || is_link($path)) {
            throw new RuntimeException('A signed release must include its CMS manifest.');
        }
        $source = File::get($path);
        ReleaseBuild::verifyConfiguration($manifest, $source);
        $configuration = \KraftReleaseBuild::configuration($source);
        if (
            $configuration['requires'] != $manifest['requirements'] ||
            KraftBuiltinPackages::inventory($configuration['bundled'] ?? []) !==
                KraftBuiltinPackages::inventory($manifest['builtins'] ?? []) ||
            KraftUpdateFiles::preservation($configuration['update']['preserve'] ?? [], ['manifest.json']) !==
                KraftUpdateFiles::preservation($manifest['preserve'] ?? [], ['manifest.json']) ||
            KraftUpdateFiles::synchronization($configuration['update']['sync'] ?? []) !==
                KraftUpdateFiles::synchronization(array_key_exists('sync', $manifest) ? $manifest['sync'] : [])
        ) {
            throw new RuntimeException('The packaged CMS manifest does not match its signed update policy.');
        }
    }
}
