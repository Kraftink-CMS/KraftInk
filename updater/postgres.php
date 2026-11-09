<?php

declare(strict_types=1);

readonly class KraftPostgresSnapshot
{
    private const string UnsafeScope = <<<'SQL'
        EXISTS (
            SELECT 1 FROM pg_catalog.pg_namespace
            WHERE nspname NOT IN ('public', 'information_schema') AND left(nspname, 3) <> 'pg_'
        )
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_extension WHERE extname <> 'plpgsql')
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_event_trigger)
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_publication)
        OR EXISTS (SELECT subname FROM pg_catalog.pg_subscription WHERE subdbid = (
            SELECT oid FROM pg_catalog.pg_database WHERE datname = current_database()
        ))
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_foreign_data_wrapper)
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_largeobject_metadata)
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_cast WHERE oid >= 16384)
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_am WHERE oid >= 16384)
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_transform WHERE oid >= 16384)
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_language WHERE oid >= 16384)
        OR EXISTS (
            SELECT 1 FROM pg_catalog.pg_shdepend
            WHERE dbid = (SELECT oid FROM pg_catalog.pg_database WHERE datname = current_database())
                AND deptype = 'o'
                AND refobjid <> current_user::regrole
                AND pg_catalog.pg_get_userbyid(refobjid) <> 'pg_database_owner'
        )
        SQL;

    private const string Identity = <<<'SQL'
        SELECT oid::text AS oid, host(inet_server_addr()) AS address, inet_server_port()::text AS port
        FROM pg_catalog.pg_database WHERE datname = current_database()
        SQL;

    private const string PublicOwner = <<<'SQL'
        (SELECT pg_catalog.pg_get_userbyid(nspowner) FROM pg_catalog.pg_namespace WHERE nspname = 'public')
        SQL;

    private const string Relations = <<<'SQL'
        SELECT relname AS name, relkind AS kind
        FROM pg_catalog.pg_class
        WHERE relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = 'public')
        ORDER BY relname COLLATE "C", relkind
        SQL;

    private const string Grants = <<<'SQL'
        SELECT CASE WHEN grantee = 0 THEN NULL ELSE pg_catalog.pg_get_userbyid(grantee) END AS role,
            privilege_type AS privilege, is_grantable AS grantable
        FROM pg_catalog.pg_namespace n
        CROSS JOIN LATERAL aclexplode(COALESCE(nspacl, acldefault('n', nspowner)))
        WHERE nspname = 'public'
        ORDER BY 1 NULLS FIRST, 2, 3
        SQL;

    public function __construct(
        private array $connection,
        private array $tools,
        private string $directory,
        private string $password,
        private Closure $run,
    ) {
    }

    public function preflight(): PDO
    {
        foreach (['dump', 'restore'] as $tool) {
            if (!is_string($this->tools[$tool] ?? null) || !is_file($this->tools[$tool])) {
                throw new RuntimeException('PostgreSQL snapshots require pg_dump and psql.');
            }
        }
        $port = filter_var($this->connection['port'] ?? 5432, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if (
            !preg_match('/^[a-z0-9_.:-]+$/iD', $this->connection['host'] ?? '') ||
            !preg_match('/^[a-z0-9_.-]+$/iD', $this->connection['database'] ?? '') ||
            !preg_match('/^[a-z0-9_.-]+$/iD', $this->connection['username'] ?? '') ||
            $port === false ||
            preg_match('/[\x00\r\n]/', $this->password) ||
            !in_array($this->sslmode(), ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true) ||
            array_any(['PGHOSTADDR', 'PGSERVICE', 'PGOPTIONS'], fn (string $key) => (getenv($key) ?: '') !== '')
        ) {
            throw new RuntimeException('PostgreSQL snapshots require an explicit TCP connection.');
        }
        $database = new PDO(
            'pgsql:host='.$this->connection['host'].';port='.$port.';dbname='.$this->connection['database'].
                ';sslmode='.$this->sslmode(),
            $this->connection['username'],
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $database->exec("SET search_path = pg_catalog; SET lock_timeout = '10s'");
        $identity = $database->query(
            'SELECT current_database() AS name, current_user AS username, '.
                'pg_catalog.pg_get_userbyid(datdba) AS owner, '.self::PublicOwner.' AS schema_owner '.
                'FROM pg_catalog.pg_database WHERE datname = current_database()',
        )->fetch(PDO::FETCH_ASSOC);
        if (
            $identity['name'] !== $this->connection['database'] ||
            $identity['username'] !== $this->connection['username'] ||
            $identity['owner'] !== $identity['username'] ||
            !in_array($identity['schema_owner'], [$identity['username'], 'pg_database_owner'], true) ||
            $database->query('SHOW transaction_read_only')->fetchColumn() !== 'off' ||
            $database->query('SELECT '.self::UnsafeScope)->fetchColumn()
        ) {
            throw new RuntimeException(
                'PostgreSQL rollback requires a dedicated database and public schema owned by the application role.',
            );
        }

        return $database;
    }

    public function backup(string $file): array
    {
        $database = $this->preflight();
        $this->checkTools($database);
        $inventory = $this->inventory($database);
        $path = $this->directory.'/'.$file;
        $this->native('dump', $inventory['database']['address'], [
            '--format=plain', '--schema=public', '--lock-wait-timeout=10s', '--file='.$path,
        ]);
        if (!is_file($path) || filesize($path) < 64 || $inventory !== $this->inventory($database)) {
            throw new RuntimeException('The PostgreSQL database changed during backup or its dump is incomplete.');
        }

        return $inventory;
    }

    public function restore(string $file, array $inventory): void
    {
        $database = $this->preflight();
        $this->checkTools($database);
        if (($inventory['database'] ?? null) !== $database->query(self::Identity)->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('The PostgreSQL snapshot belongs to a different database.');
        }
        if (
            !is_array($inventory['relations'] ?? null) ||
            !is_array($inventory['rows'] ?? null) ||
            !is_array($inventory['grants'] ?? null) ||
            !is_string($inventory['schema_owner'] ?? null)
        ) {
            throw new RuntimeException('The PostgreSQL snapshot inventory is invalid.');
        }
        $guard = [
            'current_database() <> '.$database->quote($this->connection['database']),
            'current_user <> '.$database->quote($this->connection['username']),
            '(SELECT pg_catalog.pg_get_userbyid(datdba) FROM pg_catalog.pg_database WHERE datname = current_database()) <> current_user',
            'COALESCE('.self::PublicOwner.", '') NOT IN (current_user, 'pg_database_owner')",
            '(SELECT to_jsonb(identity) FROM ('.self::Identity.') identity) IS DISTINCT FROM '.
                $database->quote(json_encode($inventory['database'], JSON_THROW_ON_ERROR)).'::jsonb',
            '('.self::UnsafeScope.')',
        ];
        $verify = [
            '(SELECT COALESCE(jsonb_agg(to_jsonb(relation) ORDER BY name COLLATE "C", kind), \'[]\'::jsonb) '.
                'FROM ('.self::Relations.') relation) IS DISTINCT FROM '.
                $database->quote(json_encode($inventory['relations'], JSON_THROW_ON_ERROR)).'::jsonb',
            '(SELECT COALESCE(jsonb_agg(to_jsonb(permission) ORDER BY role NULLS FIRST, privilege, grantable), \'[]\'::jsonb) '.
                'FROM ('.self::Grants.') permission) IS DISTINCT FROM '.
                $database->quote(json_encode($inventory['grants'], JSON_THROW_ON_ERROR)).'::jsonb',
            self::PublicOwner.' IS DISTINCT FROM '.$database->quote($inventory['schema_owner']),
        ];
        foreach ($inventory['rows'] as $table => $count) {
            if ((!is_string($count) && !is_int($count)) || !preg_match('/^[0-9]+$/D', (string) $count)) {
                throw new RuntimeException('The PostgreSQL snapshot row count is invalid.');
            }
            $verify[] = '(SELECT count(*) FROM public.'.$this->identifier((string) $table).') <> '.(int) $count;
        }
        $restoreGrants = [];
        foreach ($inventory['grants'] as $grant) {
            if (!in_array($grant['privilege'], ['CREATE', 'USAGE'], true)) {
                throw new RuntimeException('Invalid PostgreSQL snapshot schema grant.');
            }
            $restoreGrants[] = 'GRANT '.$grant['privilege'].' ON SCHEMA public TO '.
                ($grant['role'] === null ? 'PUBLIC' : $this->identifier($grant['role'])).
                ($grant['grantable'] ? ' WITH GRANT OPTION;' : ';');
        }
        $prelude = $this->directory.'/postgres-restore-start.sql';
        $verification = $this->directory.'/postgres-restore-verify.sql';
        if (!class_exists(KraftUpdateFiles::class, false)) {
            require_once __DIR__.'/files.php';
        }
        try {
            KraftUpdateFiles::privateWrite(
                $prelude,
                "SET LOCAL search_path = pg_catalog;\nSET LOCAL lock_timeout = '10s';\n".
                    $this->assertSql($guard)."\nDROP SCHEMA public CASCADE;\n",
            );
            KraftUpdateFiles::privateWrite($verification, implode("\n", [...$restoreGrants, $this->assertSql($verify)]));
            $this->native('restore', $inventory['database']['address'], [
                '--no-psqlrc',
                '--quiet',
                '--set=ON_ERROR_STOP=1',
                '--single-transaction',
                '--file='.$prelude,
                '--file='.$this->directory.'/'.$file,
                '--file='.$verification,
            ]);
        } finally {
            foreach ([$prelude, $verification] as $path) {
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('Cannot remove temporary PostgreSQL restoration files.');
                }
            }
        }
    }

    private function inventory(PDO $database): array
    {
        $relations = $database->query(self::Relations)->fetchAll(PDO::FETCH_ASSOC);
        $rows = [];
        foreach ($relations as $relation) {
            if (in_array($relation['kind'], ['r', 'p'], true)) {
                $rows[$relation['name']] = (string) $database
                    ->query('SELECT count(*) FROM public.'.$this->identifier($relation['name']))
                    ->fetchColumn();
            }
        }

        return [
            'database' => $database->query(self::Identity)->fetch(PDO::FETCH_ASSOC),
            'schema_owner' => $database->query('SELECT '.self::PublicOwner)->fetchColumn(),
            'grants' => $database->query(self::Grants)->fetchAll(PDO::FETCH_ASSOC),
            'relations' => $relations,
            'rows' => $rows,
        ];
    }

    private function assertSql(array $conditions): string
    {
        $body = 'BEGIN IF '.implode(' OR ', $conditions).
            " THEN RAISE EXCEPTION 'The PostgreSQL rollback state does not match the snapshot.'; END IF; END;";

        $delimiter = '$kraft_'.bin2hex(random_bytes(8)).'$';

        return 'DO '.$delimiter.' '.$body.' '.$delimiter.';';
    }

    private function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function sslmode(): string
    {
        return $this->connection['sslmode'] ?? 'prefer';
    }

    private function checkTools(PDO $database): void
    {
        $major = intdiv((int) $database->query('SHOW server_version_num')->fetchColumn(), 10000);
        foreach (['dump' => 'pg_dump', 'restore' => 'psql'] as $key => $tool) {
            $version = ($this->run)([$this->tools[$key], '--version'], $this->environment());
            if (!preg_match('/^'.$tool.' \(PostgreSQL\) (\d+)\./m', $version, $match) || (int) $match[1] !== $major) {
                throw new RuntimeException('Use pg_dump and psql from the PostgreSQL server major version.');
            }
        }
    }

    private function native(string $tool, string $address, array $options): void
    {
        if (!class_exists(KraftUpdateFiles::class, false)) {
            require_once __DIR__.'/files.php';
        }
        $path = $this->directory.'/postgres.pgpass';
        $fields = [
            $this->connection['host'],
            $this->connection['port'] ?? 5432,
            $this->connection['database'],
            $this->connection['username'],
            $this->password,
        ];
        KraftUpdateFiles::privateWrite($path, implode(':', array_map(
            fn ($value) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $value),
            $fields,
        ))."\n");
        try {
            ($this->run)([
                $this->tools[$tool],
                '--no-password',
                '--host='.$fields[0],
                '--port='.$fields[1],
                '--dbname='.$fields[2],
                '--username='.$fields[3],
                ...$options,
            ], $this->environment() + ['PGHOSTADDR' => $address, 'PGPASSFILE' => $path]);
        } finally {
            if (!unlink($path)) {
                throw new RuntimeException('Cannot remove temporary PostgreSQL credentials.');
            }
        }
    }

    private function environment(): array
    {
        return array_filter(
            getenv(),
            fn (string $key) => !str_starts_with(strtoupper($key), 'PG') &&
                !in_array(strtoupper($key), ['DB_PASSWORD', 'DB_URL', 'KRAFT_UPDATE_DB_PASSWORD'], true),
            ARRAY_FILTER_USE_KEY,
        ) + ['PGSSLMODE' => $this->sslmode(), 'PGCONNECT_TIMEOUT' => '5'];
    }
}
