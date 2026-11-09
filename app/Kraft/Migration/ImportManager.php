<?php

namespace App\Kraft\Migration;

use App\Models\Imports\ImportCredential;
use App\Models\Imports\ImportRecord;
use App\Models\Imports\ImportRun;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Migration\Contracts\SourceAdapter;
use App\Kraft\Migration\Contracts\ValidatesSource;
use App\Kraft\Settings;
use App\Kraft\System\OperationLock;
use App\Kraft\Wallet\Money;
use App\Kraft\Wallet\Wallet;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use Generator;
use JsonException;
use Random\RandomException;

final class ImportManager
{
    public function __construct(private MappingRegistry $registry, private CoreMappings $core)
    {
    }

    public function isRunning(): bool
    {
        $path = config('imports.directory').DIRECTORY_SEPARATOR.'import.lock';
        if (!is_file($path)) {
            return false;
        }
        $handle = fopen($path, 'r+');
        if ($handle === false) {
            return true;
        }
        $available = flock($handle, LOCK_EX | LOCK_NB);
        if ($available) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$available;
    }

    /**
     * @throws JsonException
     */
    public function analyze(string $sourceName, string $connection, array $options = []): array
    {
        $adapter = $this->adapter($sourceName);
        $source = DB::connection($connection);
        $target = DB::connection();
        $this->separateDatabases($source, $target);
        $adapter->registerMappings($this->registry, $this->core);
        $options = [
            'mode' => $options['mode'] ?? 'merge',
            'existing_users' => $options['existing_users'] ?? 'reject',
            'tables' => $options['tables'] ?? null,
            'game' => $options['game'] ?? app(GameRegistry::class)->activeId(),
        ];
        if (
            !in_array($options['mode'], ['merge', 'replace'], true) ||
            !in_array($options['existing_users'], ['preserve', 'reject'], true)
        ) {
            throw new RuntimeException('Invalid import policy.');
        }
        if (
            $options['game'] !== null &&
            (!is_string($options['game']) || !array_key_exists($options['game'], app(GameRegistry::class)->all()))
        ) {
            throw new RuntimeException('Invalid target game.');
        }
        $schema = $source->getSchemaBuilder();
        $tables = [];
        $prefix = $source->getTablePrefix();
        foreach ($schema->getTableListing($schema->getCurrentSchemaListing(), schemaQualified: false) as $physical) {
            if ($prefix !== '' && !str_starts_with($physical, $prefix)) {
                continue;
            }
            $table = $prefix === '' ? $physical : substr($physical, strlen($prefix));
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table)) {
                throw new RuntimeException('Unsupported source table identifier.');
            }
            $tables[$table] = [
                'columns' => $schema->getColumnListing($table),
                'count' => $source->table($table)->count(),
            ];
            $tables[$table]['checksum'] = $this->sourceChecksum($source, $table, $tables[$table]['columns']);
        }
        ksort($tables);
        $options['locale'] =
            $options['locale'] ?? ($adapter->sourceLocale($source, $tables) ?? config('imports.locale', 'fr'));
        $errors = [];
        foreach ($adapter->requirements() as $table => $columns) {
            $missing = array_diff($columns, $tables[$table]['columns'] ?? []);
            if ($missing !== []) {
                $errors[] = __('system.imports.source_contract', [
                    'table' => $table,
                    'columns' => implode(', ', $missing),
                ]);
            }
        }
        $mapped = [];
        $plugins = [];
        foreach ($this->registry->mappings($sourceName) as $table => $mapping) {
            if (isset($tables[$table])) {
                $mapped[] = $table;
                if ($mapping['plugin'] !== null) {
                    $plugins[] = $mapping['plugin'];
                }
            }
        }
        if ($options['tables'] !== null) {
            if (!is_array($options['tables']) || array_diff($options['tables'], $mapped) !== []) {
                throw new RuntimeException('Invalid selection of source mappings.');
            }
            $options['tables'] = array_values(array_unique($options['tables']));
            sort($options['tables']);
            $mapped = array_values(array_intersect($mapped, $options['tables']));
        }
        foreach ($this->registry->mappings($sourceName) as $table => $mapping) {
            if (in_array($table, $mapped, true)) {
                foreach ($mapping['dependencies'] as $dependency) {
                    if (!in_array($dependency, $mapped, true)) {
                        $errors[] = __('system.imports.missing_dependency', [
                            'table' => $table,
                            'dependency' => $dependency,
                        ]);
                    }
                }
            }
        }
        foreach ($tables as $table => &$definition) {
            $definition['mapped'] = in_array($table, $mapped, true);
        }
        unset($definition);
        if ($errors === [] && $adapter instanceof ValidatesSource) {
            $errors = $adapter->validateSource($source, array_replace($options, ['tables' => $mapped]));
        }
        foreach (app(ExtensionManager::class)->all() as $extension) {
            foreach ($extension['imports'][$sourceName] ?? [] as $prefix) {
                foreach (array_keys($tables) as $table) {
                    if (str_starts_with($table, $prefix)) {
                        $plugins[] = $extension['id'];
                    }
                }
            }
        }
        $equivalents = app(ImportExtensions::class)->inspect($sourceName, $source, $tables);
        $plugins = [...$plugins, ...$equivalents['plugins']];
        $collisions = 0;
        $ambiguous = 0;
        $missingEmail = 0;
        $invalidBalances = 0;
        $userTable = $adapter->userTable();
        if ($errors === [] && $userTable !== null && in_array($userTable, $mapped, true)) {
            $seen = [];
            $identities = [];
            $matchedAccounts = [];
            $context = new ImportContext(
                $target,
                '',
                $adapter,
                $options,
                fn (string $table) => isset($tables[$table])
                    ? $source->table($table)->cursor()->map(fn (object $row) => (array) $row)
                    : null,
            );
            foreach ($source->table($userTable)->cursor() as $row) {
                $user = $adapter->user((array) $row, $context);
                $email = strtolower(trim((string) $user['email']));
                $missingEmail += (int) !filter_var($email, FILTER_VALIDATE_EMAIL);
                $matches = $this->core->accountMatches($user, $target);
                $identityKeys = array_map(
                    fn (array $identity) => json_encode([$identity['game'], $identity['uuid']], JSON_THROW_ON_ERROR),
                    $user['identities'],
                );
                if (
                    $matches->count() > 1 ||
                    ($email !== '' && isset($seen[$email])) ||
                    array_any($identityKeys, fn (string $key) => isset($identities[$key])) ||
                    ($matches->count() === 1 && isset($matchedAccounts[$matches->first()]))
                ) {
                    $ambiguous++;
                }
                foreach ($identityKeys as $key) {
                    $identities[$key] = true;
                }
                if ($matches->count() === 1) {
                    $matchedAccounts[$matches->first()] = true;
                }
                if ($matches->isNotEmpty()) {
                    $collisions++;
                } else {
                    try {
                        Money::parse((string) $user['balance']);
                    } catch (ValidationException) {
                        $invalidBalances++;
                    }
                }
                $seen[$email] = true;
            }
        }
        if ($collisions > 0 && $options['existing_users'] === 'reject') {
            $errors[] = __('system.imports.collisions', ['count' => $collisions]);
        }
        if ($ambiguous > 0) {
            $errors[] = __('system.imports.ambiguous', ['count' => $ambiguous]);
        }
        if ($invalidBalances > 0) {
            $errors[] = __('system.imports.invalid_balances', ['count' => $invalidBalances]);
        }
        if ($options['mode'] === 'replace') {
            foreach ($this->registry->mappings($sourceName) as $table => $mapping) {
                if (
                    isset($tables[$table]) &&
                    in_array($table, $mapped, true) &&
                    $mapping['plugin'] !== null &&
                    !$this->pluginAvailable($mapping['plugin'])
                ) {
                    $errors[] = __('system.imports.plugin_required', ['plugin' => $mapping['plugin']]);
                }
            }
        }
        $plan = [
            'source' => $sourceName,
            'options' => $options,
            'existing_accounts_preserved' => $collisions,
            'accounts_without_valid_email' => $missingEmail,
            'identity' => $this->identity($source),
            'tables' => $tables,
            'mapped' => $mapped,
            'archive_only' => array_values(array_diff(array_keys($tables), $mapped)),
            'required_plugins' => array_values(array_unique($plugins)),
            'extensions' => $equivalents,
            'errors' => $errors,
            'warnings' => [
                __('system.imports.warning_freeze'),
                __('system.imports.warning_archive'),
                __('system.imports.warning_review'),
                __('system.imports.warning_auth'),
                __('system.imports.warning_external'),
                __('system.imports.warning_currency', ['currency' => app(Wallet::class)->currency()]),
            ],
        ];
        $plan['replacement'] = $options['mode'] === 'replace' ? $this->replacementScopes($plan) : [];
        try {
            $this->guardMappings($plan, $target);
        } catch (ValidationException $exception) {
            $plan['errors'] = array_merge($plan['errors'], array_merge(...array_values($exception->errors())));
        }
        foreach ($plan['replacement'] as $table => $scope) {
            $plan['destination_counts'][$table] = $this->scopedQuery($target, $table, $scope)->count();
        }
        $plan['hash'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));

        return $plan;
    }

    public function execute(
        string $sourceName,
        string $connection,
        string $approvedPlan,
        ?string $runId = null,
        ?Closure $progress = null,
        array $options = [],
    ): array {
        return app(OperationLock::class)->exclusive(function () use (
            $sourceName,
            $connection,
            $approvedPlan,
            $runId,
            $progress,
            $options,
        ) {
            $previousLocale = config('imports.locale');
            try {
                return $this->executeLocked($sourceName, $connection, $approvedPlan, $runId, $progress, $options);
            } finally {
                config(['imports.locale' => $previousLocale]);
                app(Settings::class)->refresh();
            }
        });
    }

    private function executeLocked(
        string $sourceName,
        string $connection,
        string $approvedPlan,
        ?string $runId,
        ?Closure $progress,
        array $options,
    ): array {
        $source = DB::connection($connection);
        $target = DB::connection();
        $this->separateDatabases($source, $target);
        $lockDirectory = config('imports.directory');
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0700, true) && !is_dir($lockDirectory)) {
            throw new RuntimeException('Cannot create private import storage.');
        }
        $lock = fopen($lockDirectory.DIRECTORY_SEPARATOR.'import.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another import is already running.');
        }
        try {
            return $this->run($sourceName, $source, $connection, $approvedPlan, $runId, $progress, $options);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function run(
        string $sourceName,
        Connection $source,
        string $connection,
        string $approvedPlan,
        ?string $runId,
        ?Closure $progress,
        array $options,
    ): array {
        $target = DB::connection();
        $adapter = $this->adapter($sourceName);
        $adapter->registerMappings($this->registry, $this->core);
        $run = $runId === null ? null : ImportRun::on($target->getName())->where('id', $runId)->first();
        if ($runId !== null && $run === null) {
            throw new RuntimeException('Unknown import run.');
        }
        $plan = $run === null ? $this->analyze($sourceName, $connection, $options) : $run->plan;
        if (
            !hash_equals($plan['hash'], $approvedPlan) ||
            $plan['source'] !== $sourceName ||
            $plan['identity'] !== $this->identity($source)
        ) {
            throw new RuntimeException('The approved preflight does not match the source or import run.');
        }
        if ($plan['errors'] !== []) {
            throw new RuntimeException(implode(' ', $plan['errors']));
        }
        config(['imports.locale' => $plan['options']['locale'] ?? config('imports.locale', 'fr')]);
        if ($run?->status === 'completed') {
            return $run->report;
        }
        $runId ??= (string) Str::uuid();
        if ($run === null) {
            ImportRun::on($target->getName())->insert([
                'id' => $runId,
                'source' => $sourceName,
                'plan' => json_encode($plan, JSON_THROW_ON_ERROR),
                'status' => 'prepared',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $directory = config('imports.directory').DIRECTORY_SEPARATOR.$runId;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create run archive directory.');
        }
        $report = [
            'id' => $runId,
            'source' => $sourceName,
            'mapped' => [],
            'archive_only' => $plan['archive_only'],
            'deferred' => [],
            'archives' => [],
            'locked_users' => 0,
            'warnings' => $plan['warnings'],
            'extensions' => $plan['extensions'] ?? [],
        ];
        $replacementStarted = false;
        $initialTransactionLevel = $target->transactionLevel();
        try {
            $this->state($runId, 'snapshotting', $report);
            foreach ($plan['tables'] as $table => $definition) {
                $report['archives'][$table] = $this->snapshot($source, $table, $definition, $directory);
                $this->state($runId, 'snapshotting', $report);
                $progress?->__invoke([
                    'run' => $runId,
                    'phase' => 'snapshot',
                    'table' => $table,
                    'rows' => $definition['count'],
                ]);
            }
            $replace = $plan['options']['mode'] === 'replace';
            if ($replace) {
                $this->ensureTransactionalTables($target, []);
                if (
                    in_array($target->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true) &&
                    $target->transactionLevel() !== 0
                ) {
                    throw new RuntimeException('Replacement must own its database transaction.');
                }
                if (in_array($target->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $target->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                }
                $target->beginTransaction();
                $replacementStarted = true;
                if ($target->getDriverName() === 'pgsql') {
                    $target->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                }
                $this->guardMappings($plan, $target);
                $replacement = $this->notificationReplacementScopes($target, $plan['replacement']);
                $backupDirectory = $directory.DIRECTORY_SEPARATOR.'destination';
                $attempt = 1;
                while (is_dir($backupDirectory)) {
                    $attempt++;
                    $backupDirectory = $directory.DIRECTORY_SEPARATOR.'destination-'.$attempt;
                }
                $report['destination_backup_directory'] = basename($backupDirectory);
                if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
                    throw new RuntimeException('Cannot create the mandatory destination backup.');
                }
                foreach ($replacement as $table => $scope) {
                    $report['destination_backups'][$table] = $this->snapshot(
                        $target,
                        $table,
                        [
                            'count' => $target->table($table)->count(),
                            'columns' => $target->getSchemaBuilder()->getColumnListing($table),
                        ],
                        $backupDirectory,
                    );
                }
                foreach ($replacement as $table => $scope) {
                    $this->scopedQuery($target, $table, $scope)->delete();
                }
            }
            $context = new ImportContext(
                $target,
                $runId,
                $adapter,
                $plan['options'],
                fn (string $table) => isset($plan['tables'][$table]) ? $this->readArchive($directory, $table) : null,
            );
            foreach ($this->registry->mappings($sourceName) as $table => $mapping) {
                if (!isset($plan['tables'][$table]) || !in_array($table, $plan['mapped'], true)) {
                    continue;
                }
                if ($mapping['plugin'] !== null && !$this->pluginAvailable($mapping['plugin'])) {
                    $report['deferred'][$table] = __('system.imports.plugin_required', [
                        'plugin' => $mapping['plugin'],
                    ]);

                    continue;
                }
                $report['mapped'][$table] = 0;
                $report['converted'][$table] = 0;
                $report['archived_records'][$table] = 0;
                foreach ($this->readArchive($directory, $table) as $index => $row) {
                    $sourceId = (string) ($row[$mapping['identity']] ?? $index);
                    $checksum = hash('sha256', serialize($row));
                    $converted = $target->transaction(function () use (
                        $context,
                        $row,
                        $mapping,
                        $runId,
                        $table,
                        $sourceId,
                        $checksum,
                        $target,
                    ): bool {
                        $existing = ImportRecord::on($target->getName())
                            ->where(['run_id' => $runId, 'source_table' => $table, 'source_id' => $sourceId])
                            ->first();
                        if ($existing !== null) {
                            if (!hash_equals($existing->checksum, $checksum)) {
                                throw new RuntimeException('Archived source record changed.');
                            }

                            $metadata = $existing->metadata ?? [];

                            return ($metadata['_import_outcome'] ?? null) === 'converted' ||
                                $existing->target_table !== null;
                        }
                        $settingsBefore = $context->settingsVersion();
                        $result = $mapping['handler']($row, $context);
                        $context->adapter->afterMapping($row, $context, $table);
                        $converted =
                            ($result['target_table'] ?? null) !== null ||
                            $context->settingsVersion() !== $settingsBefore ||
                            ($result['metadata']['_import_outcome'] ?? null) === 'converted';
                        $result['metadata'] = ($result['metadata'] ?? []) + [
                            '_import_outcome' => $converted ? 'converted' : 'archive_only',
                        ];
                        ImportRecord::on($target->getName())->insert([
                            'run_id' => $runId,
                            'source_table' => $table,
                            'source_id' => $sourceId,
                            'target_table' => $result['target_table'] ?? null,
                            'target_id' => $result['target_id'] ?? null,
                            'metadata' => isset($result['metadata'])
                                ? json_encode($result['metadata'], JSON_THROW_ON_ERROR)
                                : null,
                            'checksum' => $checksum,
                            'status' => 'mapped',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        foreach ($result['aliases'] ?? [] as $aliasTable => $aliasId) {
                            ImportRecord::on($target->getName())->insert([
                                'run_id' => $runId,
                                'source_table' => $aliasTable,
                                'source_id' => (string) $aliasId,
                                'target_table' => $result['target_table'] ?? null,
                                'target_id' => $result['target_id'] ?? null,
                                'checksum' => $checksum,
                                'status' => 'alias',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        return $converted;
                    }, 3);
                    $report['mapped'][$table]++;
                    $report[$converted ? 'converted' : 'archived_records'][$table]++;
                    if ($report['mapped'][$table] % config('imports.chunk_size', 250) === 0) {
                        $this->state($runId, 'mapping', $report);
                        $progress?->__invoke([
                            'run' => $runId,
                            'phase' => 'mapping',
                            'table' => $table,
                            'rows' => $report['mapped'][$table],
                        ]);
                    }
                }
                $this->state($runId, 'mapping', $report);
            }
            foreach (
                ImportRecord::on($target->getName())
                    ->where('run_id', $runId)
                    ->where('source_table', $adapter->userTable())
                    ->orderBy('id')
                    ->cursor() as $record
            ) {
                /** @var ImportRecord $record */
                $credential = ImportCredential::on($target->getName())
                    ->where('user_id', $record->target_id)
                    ->where('run_id', $runId)
                    ->first();
                if ($credential === null) {
                    continue;
                }
                $payload = $credential->payload;
                $target
                    ->table('users')
                    ->where('id', $record->target_id)
                    ->update(['import_locked' => $payload['lock_required']]);
                $report['locked_users'] += (int) $payload['lock_required'];
            }
            if ($replace) {
                $target->commit();
                $replacementStarted = false;
            }
            $this->state($runId, $report['deferred'] === [] ? 'completed' : 'awaiting_plugins', $report);

            return $report;
        } catch (Throwable $exception) {
            if ($replacementStarted) {
                $target->rollBack($initialTransactionLevel);
            }
            ImportRun::on($target->getName())
                ->where('id', $runId)
                ->update([
                    'status' => 'failed',
                    'report' => json_encode($report, JSON_THROW_ON_ERROR),
                    'error' => 'imports.interrupted',
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }

    /**
     * @throws JsonException
     */
    private function sourceChecksum(Connection $source, string $table, array $columns): string
    {
        $digest = hash_init('sha256');
        $query = $source->table($table);
        foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
            $query->orderBy($column);
        }
        foreach ($query->cursor() as $record) {
            $encoded = [];
            foreach ((array) $record as $key => $value) {
                if (is_resource($value)) {
                    $value = stream_get_contents($value);
                }
                $encoded[$key] = is_string($value) ? ['binary' => base64_encode($value)] : ['value' => $value];
            }
            ksort($encoded);
            hash_update($digest, json_encode($encoded, JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($digest);
    }

    /**
     * @throws JsonException
     * @throws RandomException
     */
    private function snapshot(Connection $source, string $table, array $definition, string $directory): array
    {
        $path = $this->archivePath($directory, $table);
        $metadataPath = $path.'.meta';
        if (is_file($path) && is_file($metadataPath)) {
            $metadata = json_decode(file_get_contents($metadataPath), true);
            if (!is_array($metadata) || !isset($metadata['sha256'])) {
                throw new RuntimeException(
                    'Snapshot metadata is incomplete. Restore its protected manifest before resuming.',
                );
            }
            if (!hash_equals($metadata['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Archive checksum mismatch: '.$table);
            }

            return $metadata;
        }
        $handle = fopen($path.'.part', 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write private source snapshot.');
        }
        $count = 0;
        $digest = hash_init('sha256');
        try {
            $query = $source->table($table);
            foreach (in_array('id', $definition['columns'], true) ? ['id'] : $definition['columns'] as $column) {
                $query->orderBy($column);
            }
            foreach ($query->cursor() as $record) {
                $encoded = [];
                foreach ((array) $record as $key => $value) {
                    if (is_resource($value)) {
                        $value = stream_get_contents($value);
                    }
                    $encoded[$key] = is_string($value) ? ['binary' => base64_encode($value)] : ['value' => $value];
                }
                ksort($encoded);
                hash_update($digest, json_encode($encoded, JSON_THROW_ON_ERROR)."\n");
                $line = Crypt::encryptString(json_encode($encoded, JSON_THROW_ON_ERROR))."\n";
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new RuntimeException('Incomplete snapshot write.');
                }
                $count++;
            }
            if ($count !== $definition['count']) {
                throw new RuntimeException('Source changed during snapshot: '.$table);
            }
            if (isset($definition['checksum']) && !hash_equals($definition['checksum'], hash_final($digest))) {
                throw new RuntimeException('Source values changed during snapshot: '.$table);
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }
        if (is_file($path) && !rename($path, $path.'.orphan-'.bin2hex(random_bytes(6)))) {
            throw new RuntimeException('Cannot preserve an incomplete snapshot.');
        }
        if (!rename($path.'.part', $path)) {
            throw new RuntimeException('Cannot finalize snapshot.');
        }
        $metadata = [
            'rows' => $count,
            'sha256' => hash_file('sha256', $path),
            'columns' => $source->getSchemaBuilder()->getColumns($table),
        ];
        File::replace($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));

        return $metadata;
    }

    /**
     * @throws JsonException
     */
    public function readArchive(string $directory, string $table): Generator
    {
        $path = $this->archivePath($directory, $table);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Missing encrypted source snapshot.');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $encoded = json_decode(Crypt::decryptString(trim($line)), true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($encoded)) {
                    throw new RuntimeException('Invalid encrypted source snapshot row.');
                }
                yield array_map(
                    static fn (array $value) => array_key_exists('binary', $value)
                        ? base64_decode($value['binary'], true)
                        : $value['value'],
                    $encoded,
                );
            }
        } finally {
            fclose($handle);
        }
    }

    private function pluginAvailable(string $plugin): bool
    {
        $extension = app(ExtensionManager::class)->all()[$plugin] ?? null;

        return $extension !== null && $extension['type'] === 'plugin' && $extension['enabled'];
    }

    private function archivePath(string $directory, string $table): string
    {
        return $directory.DIRECTORY_SEPARATOR.hash('sha256', $table).'.encrypted.jsonl';
    }

    /**
     * @throws JsonException
     */
    private function state(string $id, string $status, array &$report): void
    {
        $report['notices'] = ImportRecord::query()
            ->where('run_id', $id)
            ->where('source_table', '__notices')
            ->orderBy('id')
            ->pluck('metadata')
            ->all();
        ImportRun::query()
            ->where('id', $id)
            ->update([
                'status' => $status,
                'report' => json_encode($report, JSON_THROW_ON_ERROR),
                'error' => null,
                'updated_at' => now(),
            ]);
        $directory = config('imports.directory').DIRECTORY_SEPARATOR.$id;
        if (is_dir($directory)) {
            File::replace(
                $directory.DIRECTORY_SEPARATOR.'progress.json',
                json_encode(
                    ['id' => $id, 'status' => $status, 'report' => $report, 'updated_at' => now()->toIso8601String()],
                    JSON_THROW_ON_ERROR,
                ),
            );
        }
    }

    private function adapter(string $name): SourceAdapter
    {
        /** @var SourceAdapters $sourceAdapters */
        $sourceAdapters = app(SourceAdapters::class);

        return $sourceAdapters->get($name);
    }

    /**
     * @throws JsonException
     */
    private function identity(Connection $connection): string
    {
        $config = $connection->getConfig();

        return hash(
            'sha256',
            json_encode(
                [
                    $config['driver'],
                    $config['host'] ?? '',
                    $config['port'] ?? '',
                    $config['database'],
                    $config['prefix'] ?? '',
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @throws JsonException
     */
    private function separateDatabases(Connection $source, Connection $target): void
    {
        if (
            $source->getPdo() === $target->getPdo() ||
            ($this->identity($source) === $this->identity($target) && $source->getConfig('database') !== ':memory:')
        ) {
            throw new RuntimeException('The source must be a separate database with read-only credentials.');
        }
        if ($source->getDriverName() === 'sqlite' && $target->getDriverName() === 'sqlite') {
            $sourcePath = $source->getConfig('database');
            $targetPath = $target->getConfig('database');
            if ($sourcePath !== ':memory:' && $targetPath !== ':memory:') {
                $sourcePath = realpath($sourcePath);
                $targetPath = realpath($targetPath);
                if (
                    $sourcePath !== false &&
                    $targetPath !== false &&
                    (PHP_OS_FAMILY === 'Windows'
                        ? strcasecmp($sourcePath, $targetPath) === 0
                        : $sourcePath === $targetPath)
                ) {
                    throw new RuntimeException('The source resolves to the destination database file.');
                }
            }
        }
        if (
            in_array($source->getDriverName(), ['mysql', 'mariadb'], true) &&
            in_array($target->getDriverName(), ['mysql', 'mariadb'], true)
        ) {
            $query =
                'SELECT @@hostname AS server_host, @@port AS server_port, @@datadir AS data_directory, DATABASE() AS database_name';
            $sourceIdentity = (array) $source->selectOne($query);
            $targetIdentity = (array) $target->selectOne($query);
            if ($sourceIdentity === $targetIdentity) {
                throw new RuntimeException('The source resolves to the destination database server and schema.');
            }
        }
        if ($source->getDriverName() === 'pgsql' && $target->getDriverName() === 'pgsql') {
            $query =
                "SELECT current_database() AS database_name, current_setting('port') AS server_port, EXTRACT(EPOCH FROM pg_postmaster_start_time()) AS server_start";
            if ((array) $source->selectOne($query) === (array) $target->selectOne($query)) {
                throw new RuntimeException('The source resolves to the destination database server and schema.');
            }
        }
    }

    private function replacementScopes(array $plan): array
    {
        $scopes = [];
        foreach ($this->registry->mappings($plan['source']) as $sourceTable => $mapping) {
            if (
                !in_array($sourceTable, $plan['mapped'], true) ||
                ($mapping['plugin'] !== null && !$this->pluginAvailable($mapping['plugin']))
            ) {
                continue;
            }
            $replacements =
                $mapping['replacements'] instanceof Closure ? $mapping['replacements']() : $mapping['replacements'];
            foreach ($replacements as $table => $scope) {
                if (!DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                if (!isset($scopes[$table])) {
                    $scopes[$table] = $scope;
                } elseif ($scopes[$table] === [] || $scope === []) {
                    $scopes[$table] = [];
                } else {
                    foreach ($scope as $column => $values) {
                        $scopes[$table][$column] = array_values(
                            array_unique(array_merge($scopes[$table][$column] ?? [], $values)),
                        );
                    }
                }
            }
        }

        return $this->notificationReplacementScopes(DB::connection(), $scopes);
    }

    private function notificationReplacementScopes(Connection $target, array $scopes): array
    {
        if (!isset($scopes['notification_deliveries'])) {
            return $scopes;
        }
        $deliveries = $this->scopedQuery($target, 'notification_deliveries', $scopes['notification_deliveries']);
        if (
            (clone $deliveries)->where('status', 'sending')->where('started_at', '>=', now()->subMinutes(10))->exists()
        ) {
            throw new RuntimeException(__('notifications.busy'));
        }
        $ids = (clone $deliveries)->whereNotNull('notice_id')->pluck('notice_id')->all();

        return [
            'community_notice_reads' => ['notice_id' => $ids],
            'community_notices' => ['id' => $ids],
        ] + $scopes;
    }

    private function guardMappings(array $plan, Connection $target): void
    {
        foreach ($this->registry->mappings($plan['source']) as $table => $mapping) {
            if (
                in_array($table, $plan['mapped'], true) &&
                $mapping['guard'] !== null &&
                ($mapping['plugin'] === null || $this->pluginAvailable($mapping['plugin']))
            ) {
                $mapping['guard']($target, $plan['options']);
            }
        }
    }

    private function scopedQuery(Connection $connection, string $table, array $scope): Builder
    {
        $query = $connection->table($table);
        foreach ($scope as $column => $values) {
            $query->whereIn($column, $values);
        }

        return $query;
    }

    private function ensureTransactionalTables(Connection $connection, array $tables): void
    {
        if (!in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $schema = $connection->getSchemaBuilder();
        foreach ($schema->getTables($schema->getCurrentSchemaListing()) as $table) {
            if (
                ($tables === [] || in_array($table['name'], $tables, true)) &&
                strtolower($table['engine'] ?? '') !== 'innodb'
            ) {
                throw new RuntimeException('Replacement requires transactional InnoDB tables.');
            }
        }
    }
}
