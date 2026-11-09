<?php

namespace App\Kraft\Media;

use App\Models\Media\FileDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FileDeletionQueue
{
    private bool $deferred = false;

    public function schedule(string $path, string $prefix, array $references): void
    {
        $prefix = rtrim($prefix, '/');
        $this->validatePath($path, $prefix);
        $references = $this->references($references);
        $id = hash('sha256', 'local:'.$path);
        DB::transaction(function () use ($id, $path, $prefix, $references) {
            FileDeletion::query()->insertOrIgnore(
                new FileDeletion([
                    'id' => $id,
                    'prefix' => $prefix,
                    'path' => $path,
                    'references' => $references,
                    'attempts' => 0,
                    'available_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->getAttributes(),
            );
            $existing = FileDeletion::query()->where('id', $id)->lockForUpdate()->first();
            if (!$existing || $existing->path !== $path) {
                throw new RuntimeException('Cannot register private file deletion.');
            }
            $merged = $this->references($existing->references);
            foreach ($references as $table => $columns) {
                $merged[$table] = array_values(array_unique([...$merged[$table] ?? [], ...$columns]));
            }
            $existing->update([
                'prefix' => strlen($prefix) > strlen($existing->prefix) ? $prefix : $existing->prefix,
                'references' => $merged,
                'available_at' => now(),
                'updated_at' => now(),
            ]);
            DB::afterCommit(fn () => $this->attempt($id, true));
        }, 3);
    }

    public function hasDeferred(): bool
    {
        return $this->deferred;
    }

    public function prune(bool $dryRun = false): array
    {
        $ids = FileDeletion::available()->orderBy('available_at')->orderBy('id')->limit(1000)->pluck('id');
        if (!$dryRun) {
            foreach ($ids as $id) {
                $this->attempt($id);
            }
        }

        return ['files.pending_cleanup' => $ids->count()];
    }

    private function attempt(string $id, bool $immediate = false): void
    {
        try {
            DB::transaction(function () use ($id, $immediate) {
                $row = FileDeletion::query()->where('id', $id)->lockForUpdate()->first();
                if (!$row || (!$immediate && $row->available_at->isFuture())) {
                    return;
                }
                try {
                    $this->validatePath($row->path, $row->prefix);
                    $references = $this->references($row->references);
                    foreach ($references as $table => $columns) {
                        if (!Schema::hasTable($table)) {
                            if (!Storage::disk('local')->exists($row->path)) {
                                $row->delete();

                                return;
                            }
                            throw new RuntimeException('Private file reference scope is unavailable.');
                        }
                        foreach ($columns as $column) {
                            if (!Schema::hasColumn($table, $column)) {
                                throw new RuntimeException('Invalid private file reference.');
                            }
                            if (DB::table($table)->where($column, $row->path)->exists()) {
                                $row->delete();

                                return;
                            }
                        }
                    }
                    if (!Storage::disk('local')->delete($row->path)) {
                        throw new RuntimeException('Private file deletion was refused.');
                    }
                    $row->delete();
                } catch (Throwable $exception) {
                    $row->postpone();
                    $this->deferred = true;
                    report($exception);
                }
            }, 3);
        } catch (Throwable $exception) {
            $this->deferred = true;
            report($exception);
        }
    }

    private function references(array $references): array
    {
        if (!$references) {
            throw new InvalidArgumentException('Private file deletion requires reference checks.');
        }
        $normalized = [];
        foreach ($references as $table => $columns) {
            if (!is_string($table) || !preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $table)) {
                throw new InvalidArgumentException('Invalid private file reference table.');
            }
            $columns = is_array($columns) ? $columns : [$columns];
            if (!$columns) {
                throw new InvalidArgumentException('Invalid private file reference columns.');
            }
            foreach ($columns as $column) {
                if (!is_string($column) || !preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $column)) {
                    throw new InvalidArgumentException('Invalid private file reference column.');
                }
            }
            $normalized[$table] = array_values(array_unique($columns));
        }
        ksort($normalized);

        return $normalized;
    }

    private function validatePath(string $path, string $prefix): void
    {
        if (
            !preg_match('~\A[a-z0-9][a-z0-9/_-]{0,190}\z~', $prefix) ||
            str_contains($prefix, '//') ||
            !preg_match('~\A[A-Za-z0-9][A-Za-z0-9/_.-]{0,511}\z~', $path) ||
            str_contains($path, '..') ||
            !str_starts_with($path, $prefix.'/')
        ) {
            throw new InvalidArgumentException('Invalid private file deletion path.');
        }
    }
}
