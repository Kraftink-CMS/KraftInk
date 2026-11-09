<?php

namespace App\Kraft\Auth;

use App\Kraft\Extensions\ExtensionManager;
use App\Models\Accounts\User;
use InvalidArgumentException;

class ApiScopes
{
    private array $definitions = [];

    public function __construct()
    {
        foreach (['profile', 'games', 'email'] as $id) {
            $this->definitions[$id.':read'] = [
                'owner' => 'core',
                'id' => $id.':read',
                'label' => 'api.scopes.'.$id.'.label',
                'description' => 'api.scopes.'.$id.'.description',
                'permissions' => [],
                'service_accounts' => false,
            ];
        }
        foreach (['news' => 'content.manage', 'servers' => 'servers.manage'] as $resource => $permission) {
            $id = 'site:'.$resource.':read';
            $this->definitions[$id] = [
                'owner' => 'core',
                'id' => $id,
                'label' => 'api.scopes.site_'.$resource.'.label',
                'description' => 'api.scopes.site_'.$resource.'.description',
                'permissions' => [$permission],
                'service_accounts' => true,
            ];
        }
    }

    public function register(
        string $owner,
        string $id,
        string $label,
        string $description,
        array $permissions,
        bool $serviceAccounts = true,
    ): void {
        if (
            !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $owner) ||
            $owner === 'core' ||
            !preg_match('/^'.preg_quote($owner, '/').'(?::[a-z][a-z0-9_-]{0,49}){1,3}$/D', $id) ||
            $label === '' ||
            $description === '' ||
            !$permissions ||
            !array_is_list($permissions)
        ) {
            throw new InvalidArgumentException('Invalid API scope declaration.');
        }
        foreach ($permissions as $permission) {
            if (!is_string($permission) || !preg_match('/^[a-z][a-z0-9_.-]{1,119}$/D', $permission)) {
                throw new InvalidArgumentException('Invalid API scope permission.');
            }
        }
        $definition = compact('owner', 'id', 'label', 'description', 'permissions') + [
            'service_accounts' => $serviceAccounts,
        ];
        if (isset($this->definitions[$id]) && $this->definitions[$id] !== $definition) {
            throw new InvalidArgumentException('An API scope cannot replace an existing declaration.');
        }
        $this->definitions[$id] = $definition;
    }

    public function all(): array
    {
        $contributors = app(ExtensionManager::class)->runtimeContributors();

        return array_filter(
            $this->definitions,
            fn (array $definition) => $definition['owner'] === 'core' || isset($contributors[$definition['owner']]),
        );
    }

    public function availableTo(User $user): array
    {
        if ($user->isServiceAccount()) {
            return array_filter(
                $this->all(),
                fn (array $definition): bool => !$user->service_disabled_at &&
                    !$user->anonymized_at &&
                    !$user->isBanned() &&
                    $definition['service_accounts'] &&
                    in_array($definition['id'], $user->service_scopes ?? [], true),
            );
        }
        $access = app(PermissionAccess::class);

        return array_filter(
            $this->all(),
            fn (array $definition): bool => collect($definition['permissions'])->every(
                fn (string $permission): bool => $access->allows($user, $permission),
            ),
        );
    }

    public function allows(User $user, string $id): bool
    {
        return isset($this->availableTo($user)[$id]);
    }

    public function effective(User $user, array $requested): array
    {
        return array_values(array_intersect($requested, array_keys($this->availableTo($user))));
    }

    public function permissionsForServiceAccount(User $user): array
    {
        if (!$user->isServiceAccount()) {
            return [];
        }
        $definitions = $this->availableTo($user);
        if ($user->apiPermissionScopes() !== null) {
            $definitions = array_intersect_key($definitions, array_flip($user->apiPermissionScopes()));
        }

        return array_values(array_unique(array_merge([], ...array_column($definitions, 'permissions'))));
    }
}
