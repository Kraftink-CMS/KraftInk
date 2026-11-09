<?php

declare(strict_types=1);

readonly class KraftDatabaseSnapshot
{
    private const array RequiredPrivileges = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'DROP',
        'ALTER',
        'INDEX',
        'CREATE VIEW',
        'SHOW VIEW',
        'TRIGGER',
        'EVENT',
        'CREATE ROUTINE',
        'ALTER ROUTINE',
        'EXECUTE',
        'REFERENCES',
    ];

    public function __construct(private array $state, private string $directory, private ?string $password = null)
    {
    }

    /** @throws JsonException */
    public function preflight(): void
    {
        $driver = $this->state['database_driver'] ?? 'sqlite';
        if ($driver === 'sqlite') {
            $path = $this->state['database'] ?? '';
            if ($path === ':memory:' || !is_file($path) || !is_readable($path) || !is_writable($path)) {
                throw new RuntimeException('A readable and writable persistent SQLite database is required.');
            }
            $this->checkSqlite($path);

            return;
        }
        if ($driver === 'pgsql') {
            $this->postgres()->preflight();

            return;
        }
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(
                'Automatic updates support SQLite, MySQL/MariaDB and PostgreSQL.',
            );
        }
        $database = $this->mysql();
        $this->privileges($database);
        $this->inventory($database, true);
        foreach (['dump', 'restore'] as $tool) {
            if (empty($this->state['tools'][$tool]) || !is_file($this->state['tools'][$tool])) {
                throw new RuntimeException('Native database snapshot utilities are required.');
            }
        }
    }

    /** @throws JsonException */
    public function backup(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create private snapshot storage.');
        }
        chmod($this->directory, 0700);
        if (is_file($this->directory.'/database.snapshot.json')) {
            throw new RuntimeException('A completed snapshot cannot be overwritten.');
        }
        if (($this->state['database_driver'] ?? 'sqlite') === 'pgsql') {
            $this->complete('database.sql', $this->postgres()->backup('database.sql'));

            return;
        }
        $this->preflight();
        if (($this->state['database_driver'] ?? 'sqlite') === 'sqlite') {
            $database = $this->sqlite($this->state['database']);
            $path = $this->directory.'/database.sqlite';
            $database->exec('VACUUM INTO '.$database->quote($path));
            $database = null;
            $this->checkSqlite($path);
            $this->complete('database.sqlite', []);

            return;
        }
        $connection = $this->state['connection'];
        $database = $this->mysql();
        $inventory = $this->inventory($database, true);
        $isMaria = stripos((string) $database->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
        $version = $this->run([$this->state['tools']['dump'], '--version']);
        if (
            $isMaria !== (stripos($version, 'mariadb') !== false) ||
            !preg_match('/mysqldump|mariadb-dump/i', $version)
        ) {
            throw new RuntimeException('Use a native dump utility matching the MySQL or MariaDB server family.');
        }
        $command = [
            $this->state['tools']['dump'],
            '--defaults-file='.$this->mysqlOptions($connection),
            '--single-transaction',
            '--routines',
            '--events',
            '--triggers',
            '--skip-add-locks',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--result-file='.$this->directory.'/database.sql',
        ];
        if (!$isMaria) {
            $command[] = '--set-gtid-purged=OFF';
            if (preg_match('/\b(?:Ver|Distrib)\s+(\d+)/i', $version, $match) && (int) $match[1] >= 8) {
                $command[] = '--column-statistics=0';
            }
        }
        $command[] = $connection['database'];
        $this->run($command);
        if (
            !is_file($this->directory.'/database.sql') ||
            filesize($this->directory.'/database.sql') < 64 ||
            $inventory !== $this->inventory($database, true)
        ) {
            throw new RuntimeException('The database changed during backup or the native dump is incomplete.');
        }
        $this->complete('database.sql', $inventory);
    }

    /** @throws JsonException */
    public function restore(): void
    {
        $manifest = $this->verified();
        if (($this->state['database_driver'] ?? 'sqlite') === 'pgsql') {
            $this->postgres()->restore('database.sql', $manifest['inventory']);

            return;
        }
        if (($this->state['database_driver'] ?? 'sqlite') === 'sqlite') {
            $this->checkSqlite($this->directory.'/database.sqlite');
            foreach (['-wal', '-shm'] as $suffix) {
                if (is_file($this->state['database'].$suffix) && !unlink($this->state['database'].$suffix)) {
                    throw new RuntimeException('Stop database writers before restoring SQLite.');
                }
            }
            if (!copy($this->directory.'/database.sqlite', $this->state['database'])) {
                throw new RuntimeException('Cannot restore database snapshot.');
            }
            if (!hash_equals($manifest['sha256'], hash_file('sha256', $this->state['database']))) {
                throw new RuntimeException('The restored SQLite snapshot is incomplete.');
            }
            $this->checkSqlite($this->state['database']);

            return;
        }
        if (!in_array($this->state['database_driver'], ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('This database driver cannot be restored automatically.');
        }
        $connection = $this->state['connection'];
        $database = $this->mysql();
        $this->privileges($database);
        $current = $this->inventory($database, false);
        if (empty($this->state['tools']['restore']) || !is_file($this->state['tools']['restore'])) {
            throw new RuntimeException('The native restore utility is missing.');
        }
        $version = $this->run([$this->state['tools']['restore'], '--version']);
        $isMaria = stripos((string) $database->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
        if ($isMaria !== (stripos($version, 'mariadb') !== false) || !preg_match('/mysql|mariadb/i', $version)) {
            throw new RuntimeException('Use a native restore utility matching the MySQL or MariaDB server family.');
        }
        foreach ($current['events'] as $event) {
            $database->exec('DROP EVENT IF EXISTS '.$this->identifier($event['name']));
        }
        foreach ($current['triggers'] as $trigger) {
            $database->exec('DROP TRIGGER IF EXISTS '.$this->identifier($trigger['name']));
        }
        foreach ($current['routines'] as $routine) {
            $database->exec('DROP '.$routine['type'].' IF EXISTS '.$this->identifier($routine['name']));
        }
        foreach ($current['views'] as $view) {
            $database->exec('DROP VIEW IF EXISTS '.$this->identifier($view['name']));
        }
        $checks = (int) $database->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
        $database->exec('SET SESSION FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($current['tables'] as $table) {
                $database->exec('DROP TABLE IF EXISTS '.$this->identifier($table['name']));
            }
        } finally {
            $database->exec('SET SESSION FOREIGN_KEY_CHECKS='.$checks);
        }
        $this->run(
            [
                $this->state['tools']['restore'],
                '--defaults-file='.$this->mysqlOptions($connection),
                '--binary-mode',
                '--default-character-set=utf8mb4',
                '--database='.$connection['database'],
            ],
            $this->directory.'/database.sql',
        );
        if ($manifest['inventory'] !== $this->inventory($database, true)) {
            throw new RuntimeException('The restored inventory or row counts do not match the snapshot.');
        }
    }

    private function postgres(): KraftPostgresSnapshot
    {
        require_once __DIR__.'/postgres.php';

        return new KraftPostgresSnapshot(
            $this->state['connection'] ?? [],
            $this->state['tools'] ?? [],
            $this->directory,
            $this->connectionPassword(),
            fn (array $command, array $environment): string => $this->execute($command, null, $environment),
        );
    }

    private function mysql(): PDO
    {
        $connection = $this->state['connection'];
        foreach (
            ['unix_socket', 'socket', 'options', 'ssl_ca', 'ssl_cert', 'ssl_key', 'ssl_cipher', 'ssl_mode'] as $option
        ) {
            if (!empty($connection[$option])) {
                throw new RuntimeException(
                    'Automatic rollback currently requires classic TCP MySQL without custom socket or TLS options. No downgraded connection was attempted.',
                );
            }
        }
        foreach (['host', 'database'] as $key) {
            if (empty($connection[$key]) || preg_match('/[;\x00\r\n]/', (string) $connection[$key])) {
                throw new RuntimeException('Unsupported database connection identifier.');
            }
        }
        if (str_starts_with($connection['database'], '-')) {
            throw new RuntimeException('Unsupported database name.');
        }
        $port = filter_var($connection['port'] ?? 3306, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new RuntimeException('Unsupported database port.');
        }
        $database = new PDO(
            'mysql:host='.
                $connection['host'].
                ';port='.
                $port.
                ';dbname='.
                $connection['database'].
                ';charset=utf8mb4',
            $connection['username'],
            $this->connectionPassword(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        if ($database->query('SELECT DATABASE()')->fetchColumn() !== $connection['database']) {
            throw new RuntimeException('The connected database identity does not match this update.');
        }

        return $database;
    }

    private function privileges(PDO $database): void
    {
        $privileges = [];
        foreach ($database->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (!preg_match('/^GRANT (.+?) ON (.+?) TO /i', $grant, $match)) {
                continue;
            }
            $scope = str_replace(['`', '\\_', '\\%'], ['', '_', '%'], $match[2]);
            if ($scope !== '*.*' && $scope !== $this->state['connection']['database'].'.*') {
                continue;
            }
            if (strtoupper($match[1]) === 'ALL PRIVILEGES') {
                return;
            }
            $privileges = array_merge($privileges, array_map('trim', explode(',', strtoupper($match[1]))));
        }
        if (array_diff(self::RequiredPrivileges, $privileges) !== []) {
            throw new RuntimeException(
                'Rollback requires direct schema-wide backup, DDL and restore privileges. Table-only or unresolved role grants are insufficient.',
            );
        }
    }

    /** @throws JsonException */
    private function inventory(PDO $database, bool $baseline): array
    {
        $name = $this->state['connection']['database'];
        $owner = (string) $database->query('SELECT CURRENT_USER()')->fetchColumn();
        $foreign = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA IS NOT NULL AND ((TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA <> ?) OR (REFERENCED_TABLE_SCHEMA = ? AND TABLE_SCHEMA <> ?))',
        );
        $foreign->execute([$name, $name, $name, $name]);
        if ((int) $foreign->fetchColumn() > 0) {
            throw new RuntimeException('Cross-schema foreign keys prevent isolated rollback.');
        }
        $inventory = ['tables' => [], 'views' => [], 'routines' => [], 'events' => [], 'triggers' => []];
        $tables = $database->prepare(
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
        );
        $tables->execute([$name]);
        foreach ($tables->fetchAll(PDO::FETCH_ASSOC) as $table) {
            if ($table['TABLE_TYPE'] === 'VIEW') {
                continue;
            }
            if (
                $table['TABLE_TYPE'] !== 'BASE TABLE' ||
                ($baseline && strtoupper((string) $table['ENGINE']) !== 'INNODB')
            ) {
                throw new RuntimeException(
                    'Automatic rollback requires InnoDB base tables and supported database objects.',
                );
            }
            $inventory['tables'][] = [
                'name' => $table['TABLE_NAME'],
                'definition' => $this->structure($database, $table['TABLE_NAME']),
                'rows' => (int) $database
                    ->query('SELECT COUNT(*) FROM '.$this->identifier($table['TABLE_NAME']))
                    ->fetchColumn(),
            ];
        }
        foreach (
            [
                'views' => 'SELECT TABLE_NAME AS name, DEFINER AS definer FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
                'routines' => 'SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type, DEFINER AS definer FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME, ROUTINE_TYPE',
                'events' => 'SELECT EVENT_NAME AS name, DEFINER AS definer FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME',
                'triggers' => 'SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS target_table, DEFINER AS definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
            ] as $kind => $sql
        ) {
            $query = $database->prepare($sql);
            $query->execute([$name]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $object) {
                if ($baseline && $object['definer'] !== $owner) {
                    throw new RuntimeException('Objects defined by another account need a manual restore plan.');
                }
                unset($object['definer']);
                if (isset($object['type']) && !in_array($object['type'], ['PROCEDURE', 'FUNCTION'], true)) {
                    throw new RuntimeException('Unsupported stored routine type.');
                }
                $inventory[$kind][] = $object;
            }
        }
        if ($baseline) {
            $variables = $database
                ->query(
                    "SHOW VARIABLES WHERE Variable_name IN ('log_bin','log_bin_trust_function_creators','event_scheduler')",
                )
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            if (strtoupper($variables['event_scheduler'] ?? '') === 'ON' && $inventory['events'] !== []) {
                $enabled = $database->prepare(
                    "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=? AND STATUS='ENABLED'",
                );
                $enabled->execute([$name]);
                if ((int) $enabled->fetchColumn() > 0) {
                    throw new RuntimeException('Disable scheduled database events before creating an update snapshot.');
                }
            }
            $needsSuper =
                $inventory['triggers'] !== [] ||
                array_filter($inventory['routines'], fn (array $routine) => $routine['type'] === 'FUNCTION') !== [];
            if (
                $needsSuper &&
                strtoupper($variables['log_bin'] ?? '') === 'ON' &&
                strtoupper($variables['log_bin_trust_function_creators'] ?? '') !== 'ON' &&
                !$this->hasSuperPrivilege($database)
            ) {
                throw new RuntimeException(
                    'Binary logging requires additional restore privileges for stored functions or triggers.',
                );
            }
        }

        return $inventory;
    }

    private function hasSuperPrivilege(PDO $database): bool
    {
        return array_any($database->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN), static function (
            string $grant,
        ): bool {
            return preg_match('/^GRANT (.+?) ON \*\.\* TO /i', $grant, $match) &&
                (strtoupper($match[1]) === 'ALL PRIVILEGES' ||
                    in_array('SUPER', array_map('trim', explode(',', strtoupper($match[1]))), true));
        });
    }

    /** @throws JsonException */
    private function structure(PDO $database, string $table): string
    {
        $schema = $this->state['connection']['database'];
        $structure = [];
        foreach (
            [
                'columns' => 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',
                'keys' => 'SELECT CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,POSITION_IN_UNIQUE_CONSTRAINT,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION',
                'references' => 'SELECT CONSTRAINT_NAME,UNIQUE_CONSTRAINT_SCHEMA,UNIQUE_CONSTRAINT_NAME,MATCH_OPTION,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME',
                'options' => 'SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION,CREATE_OPTIONS,TABLE_COMMENT,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
            ] as $kind => $sql
        ) {
            $query = $database->prepare($sql);
            $query->execute([$schema, $table]);
            $structure[$kind] = $query->fetchAll(PDO::FETCH_ASSOC);
        }
        $structure['indexes'] = $database
            ->query('SHOW INDEX FROM '.$this->identifier($table))
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($structure['indexes'] as &$index) {
            unset($index['Cardinality']);
        }
        unset($index);

        return hash('sha256', json_encode($structure, JSON_THROW_ON_ERROR));
    }

    private function identifier(string $name): string
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new RuntimeException('Invalid database object identifier.');
        }

        return '`'.str_replace('`', '``', $name).'`';
    }

    private function checkSqlite(string $path): void
    {
        $database = $this->sqlite($path);
        if ($database->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('SQLite integrity verification failed.');
        }
    }

    private function sqlite(string $path): PDO
    {
        $flags = defined('Pdo\\Sqlite::ATTR_OPEN_FLAGS')
            ? constant('Pdo\\Sqlite::ATTR_OPEN_FLAGS')
            : constant('PDO::SQLITE_ATTR_OPEN_FLAGS');
        $readonly = defined('Pdo\\Sqlite::OPEN_READONLY')
            ? constant('Pdo\\Sqlite::OPEN_READONLY')
            : constant('PDO::SQLITE_OPEN_READONLY');

        return new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            $flags => $readonly,
        ]);
    }

    /** @throws JsonException */
    private function identity(): string
    {
        $connection = $this->state['connection'] ?? [];
        $driver = $this->state['database_driver'] ?? 'sqlite';

        return hash(
            'sha256',
            json_encode(
                [
                    $driver,
                    $this->state['database'] ?? null,
                    $connection['host'] ?? null,
                    $connection['port'] ?? null,
                    $connection['database'] ?? null,
                    $connection['username'] ?? null,
                    ...($driver === 'pgsql' ? [$connection['sslmode'] ?? 'prefer'] : []),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    /** @throws JsonException */
    private function complete(string $file, array $inventory): void
    {
        chmod($this->directory.'/'.$file, 0600);
        $contents = json_encode(
            [
                'format' => 1,
                'identity' => $this->identity(),
                'file' => $file,
                'sha256' => hash_file('sha256', $this->directory.'/'.$file),
                'inventory' => $inventory,
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        $path = $this->directory.'/database.snapshot.json';
        if (file_put_contents($path.'.tmp', $contents, LOCK_EX) === false) {
            throw new RuntimeException('Cannot persist the snapshot manifest.');
        }
        chmod($path.'.tmp', 0600);
        if (!rename($path.'.tmp', $path)) {
            throw new RuntimeException('Cannot finalize the snapshot manifest.');
        }
    }

    /** @throws JsonException */
    private function verified(): array
    {
        $path = $this->directory.'/database.snapshot.json';
        if (!is_file($path)) {
            throw new RuntimeException('The completed snapshot manifest is missing.');
        }
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $file = ($this->state['database_driver'] ?? 'sqlite') === 'sqlite' ? 'database.sqlite' : 'database.sql';
        if (
            ($manifest['format'] ?? null) !== 1 ||
            ($manifest['file'] ?? null) !== $file ||
            !hash_equals($manifest['identity'] ?? '', $this->identity()) ||
            !is_array($manifest['inventory'] ?? null)
        ) {
            throw new RuntimeException('The snapshot does not match this update.');
        }
        if (
            !is_file($this->directory.'/'.$file) ||
            !hash_equals($manifest['sha256'] ?? '', hash_file('sha256', $this->directory.'/'.$file))
        ) {
            throw new RuntimeException('The snapshot checksum is invalid.');
        }

        return $manifest;
    }

    private function mysqlOptions(array $connection): string
    {
        if (!class_exists(KraftUpdateFiles::class, false)) {
            require_once __DIR__.'/files.php';
        }
        $path = $this->directory.'/mysql.cnf';
        $contents = "[client]\n";
        foreach (
            [
                'host' => $connection['host'],
                'port' => $connection['port'] ?? 3306,
                'user' => $connection['username'],
                'password' => $this->connectionPassword(),
                'protocol' => 'tcp',
            ] as $key => $value
        ) {
            if (str_contains((string) $value, "\n") || str_contains((string) $value, "\r")) {
                throw new RuntimeException('Unsupported newline in database connection.');
            }
            $contents .= $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value)."\"\n";
        }
        KraftUpdateFiles::privateWrite($path, $contents);

        return $path;
    }

    private function connectionPassword(): string
    {
        $environment = getenv('KRAFT_UPDATE_DB_PASSWORD');

        return $this->password ?? ($environment === false ? '' : $environment);
    }

    private function run(array $command, ?string $input = null): string
    {
        try {
            return $this->execute($command, $input);
        } finally {
            $options = $this->directory.'/mysql.cnf';
            if (in_array('--defaults-file='.$options, $command, true) && is_file($options) && !unlink($options)) {
                throw new RuntimeException('Cannot remove temporary database credentials.');
            }
        }
    }

    private function execute(array $command, ?string $input, ?array $environment = null): string
    {
        $loginPath = $this->directory.'/disabled-login-path.cnf';
        if (file_exists($loginPath)) {
            throw new RuntimeException('The reserved native client login path must be absent.');
        }
        $environment ??= array_merge(getenv(), ['MYSQL_TEST_LOGIN_FILE' => $loginPath]);
        $logPath = $this->directory.'/database-tool.log';
        $offset = is_file($logPath) ? filesize($logPath) : 0;
        if (!is_file($logPath) && file_put_contents($logPath, '') === false) {
            throw new RuntimeException('Cannot create a private database utility log.');
        }
        chmod($logPath, 0600);
        $descriptors = [
            0 => $input ? ['file', $input, 'r'] : ['pipe', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->directory, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start database snapshot utility.');
        }
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $start = time();
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $start > 600) {
                proc_terminate($process);
                proc_close($process);
                throw new RuntimeException('Database snapshot utility timed out.');
            }
            usleep(100000);
        } while (true);
        proc_close($process);
        if ($status['exitcode'] !== 0) {
            throw new RuntimeException('Database snapshot utility failed. Inspect the private database-tool.log.');
        }
        clearstatcache(true, $logPath);

        return (string) file_get_contents($logPath, false, null, $offset);
    }
}
