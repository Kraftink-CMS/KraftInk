<?php

namespace App\Kraft\Migration;

use App\Models\Imports\ImportRecord;
use App\Kraft\Migration\Contracts\SourceAdapter;
use Illuminate\Database\Connection;
use RuntimeException;
use Closure;
use JsonException;

final class ImportContext
{
    private int $settingWrites = 0;

    private array $indexedRows = [];

    public function __construct(
        public readonly Connection $target,
        public readonly string $runId,
        public readonly SourceAdapter $adapter,
        public readonly array $options = [],
        private readonly ?Closure $sourceReader = null,
    ) {
    }

    public function sourceRows(string $table): ?iterable
    {
        return $this->sourceReader ? ($this->sourceReader)($table) : null;
    }

    public function indexedSourceRows(string $table): array
    {
        if (!isset($this->indexedRows[$table])) {
            $this->indexedRows[$table] = [];
            foreach ($this->sourceRows($table) ?? [] as $row) {
                $this->indexedRows[$table][(string) $row['id']] = $row;
            }
        }

        return $this->indexedRows[$table];
    }

    public static function normalizeLocale(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(str_replace('_', '-', $value));
        if (array_key_exists($value, config('kraft.locales'))) {
            return $value;
        }
        $language = explode('-', $value)[0];

        return array_key_exists($language, config('kraft.locales')) ? $language : null;
    }

    /**
     * @throws JsonException
     */
    public function setting(string $key, mixed $value): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,189}$/D', $key)) {
            throw new RuntimeException('Invalid imported setting key.');
        }
        $this->target
            ->table('settings')
            ->updateOrInsert(['key' => $key], ['value' => json_encode($value, JSON_THROW_ON_ERROR)]);
        $this->settingWrites++;
    }

    public function settingsVersion(): int
    {
        return $this->settingWrites;
    }

    /**
     * @throws JsonException
     */
    public function report(string $kind, array $details): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $kind)) {
            throw new RuntimeException('Invalid import notice kind.');
        }
        if (isset($details['path']) && is_string($details['path'])) {
            $parts = parse_url(
                str_starts_with($details['path'], '//') ? 'https:'.$details['path'] : $details['path'],
            );
            $details['path'] = is_array($parts)
                ? mb_substr(
                    (isset($parts['host']) ? ($parts['scheme'] ?? 'https').'://'.$parts['host'] : '').
                        ($parts['path'] ?? ''),
                    0,
                    1500,
                )
                : '';
        }
        $metadata = json_encode(['kind' => $kind] + $details, JSON_THROW_ON_ERROR);
        $identity =
            $kind === 'media'
                ? ['kind' => $kind] + array_intersect_key($details, array_flip(['table', 'id', 'field', 'path']))
                : ['kind' => $kind] + $details;
        ksort($identity);
        $checksum = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        ImportRecord::on($this->target->getName())->insertOrIgnore([
            'run_id' => $this->runId,
            'source_table' => '__notices',
            'source_id' => $checksum,
            'target_table' => null,
            'target_id' => null,
            'metadata' => $metadata,
            'checksum' => $checksum,
            'status' => 'notice',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function mapped(string $table, int|string|null $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return ImportRecord::on($this->target->getName())
            ->sourceIdentity($this->runId, $table, $id)
            ->value('target_id');
    }

    public function required(string $table, int|string|null $id): int
    {
        return $this->mapped($table, $id) ??
            throw new RuntimeException('Missing imported relation: '.$table.'.'.$id);
    }

    public function insert(string $table, array $data): int
    {
        return $this->target->table($table)->insertGetId($data);
    }

    public function remember(string $source, array $row, array $result, string $identity = 'id'): array
    {
        $id = $row[$identity] ?? null;
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $source) || (!is_string($id) && !is_int($id))) {
            throw new RuntimeException('Invalid imported relation identity.');
        }
        $checksum = hash('sha256', serialize($row));

        return $this->target->transaction(function () use ($source, $id, $result, $checksum): array {
            $key = ['run_id' => $this->runId, 'source_table' => $source, 'source_id' => (string) $id];
            $existing = ImportRecord::on($this->target->getName())->where($key)->lockForUpdate()->first();
            if ($existing !== null) {
                if (
                    !hash_equals($existing->checksum, $checksum) ||
                    $existing->target_table !== ($result['target_table'] ?? null) ||
                    (string) $existing->target_id !== (string) ($result['target_id'] ?? null)
                ) {
                    throw new RuntimeException('An imported relation is already mapped to another record.');
                }

                return [
                    'target_table' => $existing->target_table,
                    'target_id' => $existing->target_id,
                    'metadata' => $existing->metadata ?? [],
                ];
            }
            ImportRecord::on($this->target->getName())->insert(
                $key + [
                    'target_table' => $result['target_table'] ?? null,
                    'target_id' => $result['target_id'] ?? null,
                    'metadata' => isset($result['metadata'])
                        ? json_encode($result['metadata'], JSON_THROW_ON_ERROR)
                        : null,
                    'checksum' => $checksum,
                    'status' => 'mapped',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            return $result;
        }, 3);
    }

    public function metadata(string $table, int|string|null $id): array
    {
        if ($id === null) {
            return [];
        }
        $json = ImportRecord::on($this->target->getName())
            ->sourceIdentity($this->runId, $table, $id)
            ->value('metadata');

        return $json ?? [];
    }
}
