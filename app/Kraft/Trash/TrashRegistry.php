<?php

namespace App\Kraft\Trash;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use SplTempFileObject;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class TrashRegistry
{
    /** @var array<string, TrashSource> */
    private array $sources = [];

    public function register(TrashSource $source): void
    {
        if (isset($this->sources[$source->key])) {
            throw new InvalidArgumentException('Duplicate trash source.');
        }
        $this->sources[$source->key] = $source;
    }

    /** @return array<string, TrashSource> */
    public function sources(User $user): array
    {
        return array_filter($this->sources, fn (TrashSource $source) => $source->canView($user));
    }

    public function source(string $key, User $user): ?TrashSource
    {
        return $this->sources($user)[$key] ?? null;
    }

    public function restore(User $actor, string $source, int|string $item): void
    {
        $definition = $this->source($source, $actor);
        if ($definition === null) {
            abort(404);
        }
        $definition->restore($actor, $item);
    }

    /** @return array<string, TrashSource> */
    public function purgeableSources(User $actor): array
    {
        return array_filter($this->sources($actor), fn (TrashSource $source) => $source->canPurge($actor));
    }

    public function purge(User $actor, string $source, int|string $item): void
    {
        $definition = $this->source($source, $actor);
        if ($definition === null) {
            abort(404);
        }
        $definition->purge($actor, $item);
    }

    public function purgeableQuery(User $actor): Builder
    {
        return $this->buildQuery($actor, $this->purgeableSources($actor));
    }

    public function empty(User $actor): TrashPurgeResult
    {
        $sources = $this->purgeableSources($actor);
        if ($sources === []) {
            throw new HttpException(403);
        }
        $candidates = new SplTempFileObject(1024 * 1024);
        $query = $this->buildQuery($actor, $sources)->reorder();
        DB::transaction(function () use ($query, $candidates): void {
            foreach ($query->cursor() as $item) {
                $line = json_encode([$item->source, $item->id], JSON_THROW_ON_ERROR)."\n";
                if ($candidates->fwrite($line) !== strlen($line)) {
                    throw new RuntimeException('Cannot prepare the trash operation.');
                }
            }
        });
        $candidates->rewind();
        $purged = $skipped = $failed = 0;
        foreach ($candidates as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$source, $item] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            try {
                $sources[$source]->purge($actor, $item);
                $purged++;
            } catch (Throwable $exception) {
                if (
                    $exception instanceof ModelNotFoundException ||
                    ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 404)
                ) {
                    $skipped++;
                } else {
                    $failed++;
                    report($exception);
                }
            }
        }

        return new TrashPurgeResult($purged, $skipped, $failed);
    }

    public function query(User $user, ?string $source = null, ?string $search = null): Builder
    {
        $sources = $this->sources($user);
        if ($source !== null && $source !== '' && !isset($sources[$source])) {
            abort(404);
        }
        if ($source !== null && $source !== '') {
            $sources = [$source => $sources[$source]];
        }

        return $this->buildQuery($user, $sources, $search);
    }

    private function sourceQuery(User $actor, TrashSource $source): Builder
    {
        $identifierType = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'CHAR',
            'sqlsrv' => 'NVARCHAR(255)',
            default => 'TEXT',
        };

        return DB::query()
            ->fromSub($source->query($actor)->reorder(), 'source_items')
            ->selectRaw('CAST(id AS '.$identifierType.') AS id')
            ->addSelect(['title', 'deleted_at', 'restore_reason'])
            ->selectRaw('? AS source', [$source->key]);
    }

    /** @param array<string, TrashSource> $sources */
    private function buildQuery(User $actor, array $sources, ?string $search = null): Builder
    {
        $union = null;
        foreach ($sources as $definition) {
            $query = $this->sourceQuery($actor, $definition);
            $union = $union ? $union->unionAll($query) : $query;
        }
        $union ??= DB::query()
            ->selectRaw('NULL AS id, NULL AS title, NULL AS deleted_at, NULL AS restore_reason, NULL AS source')
            ->whereRaw('1 = 0');
        $query = DB::query()->fromSub($union, 'deleted_items');
        if ($search !== null && $search !== '') {
            TableQuery::whereContains($query, ['title'], $search);
        }

        return $query->orderByDesc('deleted_at')->orderBy('source')->orderByDesc('id');
    }
}
