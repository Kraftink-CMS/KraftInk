<?php

namespace App\Kraft\Auth;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Models\Accounts\ApiAccessToken;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Closure;

class ServiceAccounts
{
    public function __construct(private readonly ApiScopes $scopes, private readonly ApiTokens $tokens)
    {
    }

    public function manageableBy(User $actor): array
    {
        $this->assertOperator($actor);

        return array_filter($this->scopes->availableTo($actor), fn (array $scope): bool => $scope['service_accounts']);
    }

    /** @return Builder<User> */
    public function query(User $actor): Builder
    {
        $this->assertOperator($actor);

        return User::services()->whereNull('anonymized_at');
    }

    public function canManage(User $actor, User $service): bool
    {
        if (
            $actor->isServiceAccount() ||
            !$service->isServiceAccount() ||
            $actor->anonymized_at ||
            $actor->import_locked ||
            $actor->isBanned() ||
            $actor->force_password_reset ||
            !$actor->hasPermission('admin.access') ||
            !$actor->hasPermission('service-accounts.manage')
        ) {
            return false;
        }

        return $actor->hasPermission('*') ||
            !array_diff($service->service_scopes ?? [], array_keys($this->manageableBy($actor)));
    }

    public function assertCanManage(User $actor, User $service): void
    {
        if (!$this->canManage($actor, $service)) {
            throw new HttpException(403, __('service_accounts.delegation_denied'));
        }
    }

    private function assertOperator(User $actor): void
    {
        if (
            $actor->isServiceAccount() ||
            $actor->anonymized_at ||
            $actor->import_locked ||
            $actor->isBanned() ||
            $actor->force_password_reset ||
            !$actor->hasPermission('admin.access') ||
            !$actor->hasPermission('service-accounts.manage')
        ) {
            throw new HttpException(403);
        }
    }

    public function create(User $actor, array $data): User
    {
        $this->assertOperator($actor);

        return DB::transaction(function () use ($actor, $data): User {
            $actor = User::members()->lockForUpdate()->findOrFail($actor->id);
            $data = $this->validate($actor, $data);
            $service = new User();
            $service
                ->forceFill([
                    'account_type' => 'service',
                    'username' => $data['username'],
                    'service_description' => $data['description'] ?? null,
                    'service_scopes' => $data['scopes'],
                    'service_created_by' => $actor->id,
                    'email' => null,
                    'password' => null,
                    'local_login_enabled' => false,
                    'auth_session_version' => 0,
                    'locale' => $actor->preferredLocale(),
                ])
                ->save();
            Audit::record(
                'service_account.created',
                ['principal_id' => $service->id, 'scopes' => $service->service_scopes],
                $actor,
            );

            return $service->fresh();
        }, 3);
    }

    public function update(User $actor, User $service, array $data, ?callable $confirmScopesChange = null): User
    {
        return DB::transaction(function () use ($actor, $service, $data, $confirmScopesChange): User {
            $actor = User::members()->lockForUpdate()->findOrFail($actor->id);
            $service = User::services()->lockForUpdate()->findOrFail($service->id);
            $this->assertCanManage($actor, $service);
            $data = $this->validate($actor, $data, $service);
            $permissionsChanged = false;
            if (array_key_exists('scopes', $data)) {
                $before = $service->service_scopes ?? [];
                $after = $data['scopes'];
                sort($before);
                sort($after);
                if ($before !== $after) {
                    if ($confirmScopesChange !== null) {
                        $confirmScopesChange($actor);
                    }
                    $permissionsChanged = true;
                    $service->service_scopes = $after;
                    $service->auth_session_version++;
                    $service
                        ->apiAccessTokens()
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => now()]);
                }
            }
            if (array_key_exists('username', $data)) {
                $service->username = $data['username'];
            }
            if (array_key_exists('description', $data)) {
                $service->service_description = $data['description'];
            }
            $service->save();
            Audit::record(
                $permissionsChanged ? 'service_account.permissions_changed' : 'service_account.updated',
                ['principal_id' => $service->id, 'scopes' => $service->service_scopes],
                $actor,
            );

            return $service->fresh();
        }, 3);
    }

    private function validate(User $actor, array $data, ?User $service = null): array
    {
        $available = array_keys($this->manageableBy($actor));
        if (is_string($data['username'] ?? null)) {
            $data['username'] = trim($data['username']);
        }

        $validator = Validator::make($data, [
            'username' => [
                $service ? 'sometimes' : 'required',
                'string',
                'min:2',
                'max:60',
                'not_regex:/[\x00-\x1f\x7f]/',
                function (string $attribute, mixed $value, Closure $fail) use ($service): void {
                    if (
                        is_string($value) &&
                        User::query()
                            ->whereRaw(AccountIdentity::expression().' = ?', [mb_strtolower(trim($value))])
                            ->when($service, fn ($query) => $query->whereKeyNot($service->id))
                            ->exists()
                    ) {
                        $fail(__('validation.unique', ['attribute' => __('service_accounts.username')]));
                    }
                },
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'scopes' => [$service ? 'sometimes' : 'required', 'array', 'min:1', 'max:100'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in($available)],
        ]);

        return $validator->validate();
    }

    public function setEnabled(User $actor, User $service, bool $enabled): void
    {
        DB::transaction(function () use ($actor, $service, $enabled): void {
            $actor = User::members()->lockForUpdate()->findOrFail($actor->id);
            $service = User::services()->lockForUpdate()->findOrFail($service->id);
            $this->assertCanManage($actor, $service);
            if ($enabled === ($service->service_disabled_at === null)) {
                return;
            }
            $service
                ->forceFill([
                    'service_disabled_at' => $enabled ? null : now(),
                    'auth_session_version' => $service->auth_session_version + 1,
                ])
                ->save();
            $service
                ->apiAccessTokens()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            Audit::record(
                $enabled ? 'service_account.enabled' : 'service_account.disabled',
                ['principal_id' => $service->id],
                $actor,
            );
        }, 3);
    }

    public function issue(User $actor, User $service, string $name, array $scopes, int $minutes): array
    {
        return $this->tokens->issueService($actor, $service, $name, $scopes, $minutes);
    }

    public function rotate(User $actor, User $service, ApiAccessToken $token): array
    {
        return $this->tokens->rotateService($actor, $service, $token);
    }

    public function revoke(User $actor, User $service, ?string $tokenId = null): void
    {
        DB::transaction(function () use ($actor, $service, $tokenId): void {
            $actor = User::members()->lockForUpdate()->findOrFail($actor->id);
            $service = User::services()->lockForUpdate()->findOrFail($service->id);
            $this->assertCanManage($actor, $service);
            $query = $service->apiAccessTokens()->whereNull('revoked_at');
            if ($tokenId !== null) {
                if (!(clone $query)->whereKey($tokenId)->exists()) {
                    throw new NotFoundHttpException();
                }
                $query->whereKey($tokenId);
            }
            $query->update(['revoked_at' => now()]);
            Audit::record(
                'service_account.tokens_revoked',
                ['principal_id' => $service->id, 'token_id' => $tokenId],
                $actor,
            );
        }, 3);
    }
}
