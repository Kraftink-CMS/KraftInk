<?php

namespace App\Kraft\Trash;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\Accounts\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final readonly class TrashSource
{
    public function __construct(
        public string $key,
        public string $label,
        public array $permissions,
        public array $restorePermissions,
        private Closure $query,
        private Closure $restore,
        public string $keyType = 'integer',
        public array $purgePermissions = [],
        private ?Closure $purge = null,
        private ?Closure $authorize = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $key) || trim($label) === '') {
            throw new InvalidArgumentException('Invalid trash source.');
        }
        if (!in_array($keyType, ['integer', 'string'], true)) {
            throw new InvalidArgumentException('Invalid trash identifier type.');
        }
        foreach ([...$permissions, ...$restorePermissions, ...$purgePermissions] as $permission) {
            if (!is_string($permission) || trim($permission) === '') {
                throw new InvalidArgumentException('Invalid trash permission.');
            }
        }
    }

    public function canView(User $actor): bool
    {
        return $this->allows($actor, $this->permissions) && ($this->authorize === null || ($this->authorize)($actor));
    }

    public function canRestore(User $actor): bool
    {
        return $this->canView($actor) && $this->allows($actor, $this->restorePermissions);
    }

    public function query(User $actor): Builder
    {
        if (!$this->canView($actor)) {
            abort(404);
        }

        $query = ($this->query)($actor);
        if ($query instanceof EloquentBuilder) {
            $query = $query->toBase();
        }
        if (!($query instanceof Builder)) {
            throw new InvalidArgumentException('Trash source '.$this->key.' must return a query builder.');
        }
        try {
            $query
                ->getConnection()
                ->query()
                ->fromSub((clone $query)->reorder(), 'trash_contract')
                ->select([
                    'trash_contract.id',
                    'trash_contract.title',
                    'trash_contract.deleted_at',
                    'trash_contract.restore_reason',
                ])
                ->limit(0)
                ->get();
        } catch (QueryException $exception) {
            throw new InvalidArgumentException(
                'Trash source '.$this->key.' must expose id, title, deleted_at and restore_reason.',
                previous: $exception,
            );
        }

        return $query;
    }

    public function canRestoreItem(User $actor, object $item): bool
    {
        return $this->canRestore($actor) && property_exists($item, 'restore_reason') && $item->restore_reason === null;
    }

    public function restore(User $actor, int|string $item): void
    {
        if (!$this->canView($actor)) {
            abort(404);
        }
        if (!$this->canRestore($actor)) {
            throw new HttpException(403);
        }
        ($this->restore)($actor, $this->identifier($item));
    }

    public function canPurge(User $actor): bool
    {
        return $this->purge !== null && $this->canView($actor) && $this->allows($actor, $this->purgePermissions);
    }

    public function purge(User $actor, int|string $item): void
    {
        if (!$this->canView($actor)) {
            abort(404);
        }
        if (!$this->canPurge($actor)) {
            throw new HttpException(403);
        }
        ($this->purge)($actor, $this->identifier($item));
    }

    private function identifier(int|string $item): int|string
    {
        $identifier = (string) $item;
        if ($this->keyType === 'integer') {
            if (!preg_match('/^[1-9][0-9]*$/D', $identifier)) {
                abort(404);
            }
            $identifier = filter_var($identifier, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($identifier === false) {
                abort(404);
            }
        } else {
            if (strlen($identifier) > 255 || !preg_match('/\A[^\s\/\x00-\x1F\x7F]+\z/u', $identifier)) {
                abort(404);
            }
        }

        return $identifier;
    }

    private function allows(User $actor, array $permissions): bool
    {
        return array_all($permissions, fn ($permission) => $actor->hasPermission($permission));
    }
}
