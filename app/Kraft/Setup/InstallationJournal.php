<?php

namespace App\Kraft\Setup;

use App\Exceptions\InstallationFailure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Random\RandomException;
use RuntimeException;
use Throwable;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

class InstallationJournal
{
    private array $state = [];

    private string $path;

    private string $key;

    private ?Connection $connection = null;

    private ?array $binding = null;

    private bool $active = false;

    private bool $managed = false;

    public function start(Connection $connection): void
    {
        if ($this->active || $connection->transactionLevel() !== 0) {
            throw new InstallationFailure('system.install.journal.busy');
        }
        $config = $connection->getConfig();
        $driver = $connection->getDriverName();
        if (
            (!array_key_exists($driver, Requirements::DATABASE_PORTS) && $driver !== 'mariadb') ||
            !empty($config['prefix']) ||
            !empty($config['url']) ||
            !empty($config['read']) ||
            !empty($config['write']) ||
            is_array($config['host'] ?? null)
        ) {
            throw new InstallationFailure('system.install.journal.connection');
        }
        $database =
            $driver === 'sqlite'
                ? realpath($connection->getDatabaseName())
                : $connection->getDatabaseName();
        if (!$database) {
            throw new InstallationFailure('system.install.journal.connection');
        }
        $coordinates =
            $driver === 'sqlite'
                ? [$driver, $database]
                : [
                    $driver,
                    strtolower(trim((string) ($config['host'] ?? '127.0.0.1'))),
                    (int) ($config['port'] ?? Requirements::DATABASE_PORTS[$driver] ?? 3306),
                    $database,
                ];
        $identity = hash('sha256', json_encode($coordinates, JSON_THROW_ON_ERROR));
        $this->path = InstallationFiles::journal(storage_path(), $identity);
        $this->directory(dirname($this->path));
        $key = (string) config('app.key');
        $this->key = str_starts_with($key, 'base64:') ? (base64_decode(substr($key, 7), true) ?: '') : $key;
        if ($this->key === '') {
            throw new InstallationFailure('system.install.journal.invalid');
        }
        $this->connection = $connection;
        $baseline = $this->releaseSignature();
        if (is_file($this->path)) {
            $this->load();
            if (
                ($this->state['identity'] ?? null) !== $identity ||
                ($this->state['baseline'] ?? null) !== $baseline ||
                ($this->state['version'] ?? null) !== config('manifest.version')
            ) {
                throw new InstallationFailure('system.install.journal.changed_release');
            }
            $this->resetRolledBackMigration();
            $this->verify();
        } else {
            if ($this->tables() !== [] || $connection->getSchemaBuilder()->getViews() !== []) {
                throw new InstallationFailure('system.install.journal.foreign_database');
            }
            $this->state = [
                'format' => 1,
                'identity' => $identity,
                'baseline' => $baseline,
                'version' => config('manifest.version'),
                'status' => 'preparing',
                'created_at' => now()->toIso8601String(),
                'tables' => [],
                'operations' => [],
            ];
            $this->save();
        }
        $this->binding = app()->getBindings()[Blueprint::class] ?? null;
        app()->bind(
            Blueprint::class,
            fn ($app, $parameters) => new InstallationBlueprint(
                $parameters['connection'],
                $parameters['table'],
                $parameters['callback'] ?? null,
                $this,
            ),
        );
        Schema::clearResolvedInstance('db.schema');
        $this->active = true;
        $connection->beforeExecuting(function ($query) {
            if (
                $this->active &&
                !$this->managed &&
                preg_match('/^\s*(?:create|alter|drop|rename|truncate)\b/i', $query)
            ) {
                throw new InstallationFailure('system.install.journal.untracked');
            }
        });
    }

    public function apply(Connection $connection, string $table, array $statements, bool $creating): void
    {
        if (!$this->active || $connection !== $this->connection || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) {
            throw new InstallationFailure('system.install.journal.untracked');
        }
        $hashes = array_map(fn ($sql) => hash('sha256', $sql), $statements);
        $operation = hash('sha256', json_encode([$table, $creating, $hashes], JSON_THROW_ON_ERROR));
        if (!isset($this->state['tables'][$table])) {
            if (!$creating || $this->signature($table) !== null) {
                throw new InstallationFailure('system.install.journal.foreign_database');
            }
            $this->state['tables'][$table] = [
                'signature' => null,
                'transactional' => $connection->transactionLevel() > 0,
                'create' => $operation,
            ];
        } elseif ($creating && $this->state['tables'][$table]['create'] !== $operation) {
            throw new InstallationFailure('system.install.journal.changed_release');
        }
        if (!isset($this->state['operations'][$operation])) {
            $this->state['operations'][$operation] = [
                'table' => $table,
                'hashes' => $hashes,
                'completed' => 0,
                'pending' => null,
            ];
        }
        $entry = &$this->state['operations'][$operation];
        if ($this->signature($table) !== $this->state['tables'][$table]['signature']) {
            throw new InstallationFailure('system.install.journal.ambiguous');
        }
        for ($index = $entry['completed']; $index < count($statements); $index++) {
            $entry['pending'] = $index;
            $this->state['status'] = 'migrating';
            $this->save();
            $this->managed = true;
            try {
                $connection->statement($statements[$index]);
            } finally {
                $this->managed = false;
            }
            $this->state['tables'][$table]['signature'] = $this->signature($table);
            $entry['completed'] = $index + 1;
            $entry['pending'] = null;
            $this->save();
        }
    }

    public function ready(): void
    {
        $this->verify();
        $this->state['status'] = 'provisioned';
        $this->state['provisioned_at'] = now()->toIso8601String();
        $this->save();
    }

    public function release(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        if ($this->binding) {
            app()->bind(Blueprint::class, $this->binding['concrete'], $this->binding['shared']);
        } else {
            app()->offsetUnset(Blueprint::class);
        }
        Schema::clearResolvedInstance('db.schema');
    }

    private function resetRolledBackMigration(): void
    {
        if (!in_array($this->connection->getDriverName(), ['sqlite', 'pgsql'], true) || $this->state['status'] === 'provisioned') {
            return;
        }
        $tables = array_keys(array_filter($this->state['tables'], fn ($table) => $table['transactional']));
        if ($tables === [] || array_intersect($tables, $this->tables()) !== []) {
            return;
        }
        $migrationTable = config('database.migrations.table', 'migrations');
        if (
            $this->connection->getSchemaBuilder()->hasTable($migrationTable) &&
            $this->connection
                ->table($migrationTable)
                ->where('migration', '0001_01_01_000000_create_cms_schema')
                ->exists()
        ) {
            throw new InstallationFailure('system.install.journal.ambiguous');
        }
        foreach ($tables as $table) {
            unset($this->state['tables'][$table]);
        }
        $this->state['operations'] = array_filter(
            $this->state['operations'],
            fn ($operation) => !in_array($operation['table'], $tables, true),
        );
        $this->save();
    }

    private function tables(): array
    {
        return $this->connection->getSchemaBuilder()->getTableListing(schemaQualified: false);
    }

    /**
     * @throws JsonException
     */
    private function verify(): void
    {
        if (
            array_diff($this->tables(), array_keys($this->state['tables'])) !== [] ||
            $this->connection->getSchemaBuilder()->getViews() !== []
        ) {
            throw new InstallationFailure('system.install.journal.foreign_database');
        }
        foreach ($this->state['tables'] as $table => $definition) {
            if ($this->signature($table) !== $definition['signature']) {
                throw new InstallationFailure('system.install.journal.ambiguous');
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function signature(string $table): ?string
    {
        $schema = $this->connection->getSchemaBuilder();
        if (!$schema->hasTable($table)) {
            return null;
        }
        $parts = [$schema->getColumns($table), $schema->getIndexes($table), $schema->getForeignKeys($table)];
        foreach ($parts as &$rows) {
            usort(
                $rows,
                fn ($left, $right) => strcmp(
                    json_encode($left, JSON_THROW_ON_ERROR),
                    json_encode($right, JSON_THROW_ON_ERROR),
                ),
            );
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function load(): void
    {
        if (is_link($this->path) || filesize($this->path) > 4194304) {
            throw new InstallationFailure('system.install.journal.invalid');
        }
        try {
            $envelope = json_decode(file_get_contents($this->path), true, 64, JSON_THROW_ON_ERROR);
            $json = json_encode($envelope['data'], JSON_THROW_ON_ERROR);
            if (
                ($envelope['data']['format'] ?? null) !== 1 ||
                !is_string($envelope['mac'] ?? null) ||
                !hash_equals(hash_hmac('sha256', $json, $this->key), $envelope['mac'])
            ) {
                throw new RuntimeException();
            }
            $this->state = $envelope['data'];
            $this->validateState();
        } catch (Throwable) {
            throw new InstallationFailure('system.install.journal.invalid');
        }
    }

    private function save(): void
    {
        try {
            $json = json_encode($this->state, JSON_THROW_ON_ERROR);
            $contents = json_encode(
                ['data' => $this->state, 'mac' => hash_hmac('sha256', $json, $this->key)],
                JSON_THROW_ON_ERROR,
            );
            $temporary = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';
        } catch (RandomException|JsonException $exception) {
            throw new InstallationFailure('system.install.journal.storage', $exception);
        }
        $handle = fopen($temporary, 'x+b');
        if (!$handle) {
            throw new InstallationFailure('system.install.journal.storage');
        }
        try {
            if (
                !chmod($temporary, 0600) ||
                fwrite($handle, $contents) !== strlen($contents) ||
                !fflush($handle) ||
                (function_exists('fsync') && !fsync($handle))
            ) {
                throw new InstallationFailure('system.install.journal.storage');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($temporary);
            throw $exception;
        }
        fclose($handle);
        if (!rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new InstallationFailure('system.install.journal.storage');
        }
    }

    private function directory(string $directory): void
    {
        $cursor = $directory;
        while ($cursor !== dirname($cursor)) {
            if (is_link($cursor)) {
                throw new InstallationFailure('system.install.journal.storage');
            }
            $cursor = dirname($cursor);
        }
        if ((!is_dir($directory) && !mkdir($directory, 0700, true)) || !chmod($directory, 0700)) {
            throw new InstallationFailure('system.install.journal.storage');
        }
    }

    /**
     * @throws JsonException
     */
    private function releaseSignature(): string
    {
        $files = [];
        foreach ([app_path(), base_path('database/migrations')] as $root) {
            foreach (
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file
            ) {
                if ($file->isLink()) {
                    throw new InstallationFailure('system.install.journal.changed_release');
                }
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = hash_file(
                        'sha256',
                        $file->getPathname(),
                    );
                }
            }
        }
        ksort($files);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    private function validateState(): void
    {
        if (
            !is_array($this->state['tables'] ?? null) ||
            !is_array($this->state['operations'] ?? null) ||
            count($this->state['tables']) > 1000 ||
            count($this->state['operations']) > 5000 ||
            !in_array($this->state['status'] ?? null, ['preparing', 'migrating', 'provisioned'], true)
        ) {
            throw new InstallationFailure('system.install.journal.invalid');
        }
        foreach ($this->state['tables'] as $table => $entry) {
            if (
                !is_string($table) ||
                !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) ||
                !is_array($entry) ||
                !is_bool($entry['transactional'] ?? null) ||
                !is_string($entry['create'] ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D', $entry['create']) ||
                (!is_null($entry['signature'] ?? null) &&
                    (!is_string($entry['signature']) || !preg_match('/^[a-f0-9]{64}$/D', $entry['signature'])))
            ) {
                throw new InstallationFailure('system.install.journal.invalid');
            }
        }
        foreach ($this->state['operations'] as $operation => $entry) {
            if (
                !is_string($operation) ||
                !preg_match('/^[a-f0-9]{64}$/D', $operation) ||
                !is_array($entry) ||
                !is_string($entry['table'] ?? null) ||
                !isset($this->state['tables'][$entry['table']]) ||
                !is_array($entry['hashes'] ?? null) ||
                !array_is_list($entry['hashes']) ||
                count($entry['hashes']) > 1000 ||
                !is_int($entry['completed'] ?? null) ||
                $entry['completed'] < 0 ||
                $entry['completed'] > count($entry['hashes']) ||
                (!is_null($entry['pending'] ?? null) && $entry['pending'] !== $entry['completed'])
            ) {
                throw new InstallationFailure('system.install.journal.invalid');
            }
            foreach ($entry['hashes'] as $hash) {
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new InstallationFailure('system.install.journal.invalid');
                }
            }
        }
    }
}
