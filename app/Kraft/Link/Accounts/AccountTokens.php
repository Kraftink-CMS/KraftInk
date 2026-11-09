<?php

namespace App\Kraft\Link\Accounts;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Kraft\Auth\ApiTokens;
use App\Kraft\Games\GameRegistry;
use App\Kraft\System\OperationLock;
use App\Models\Servers\LinkAccountToken;
use App\Models\Servers\Server;
use App\Models\Accounts\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountTokens
{
    public const array SCOPES = [
        'profile:read' => ['users.manage'],
        'account:create' => ['users.manage', 'users.create', 'users.edit-personal'],
        'account:email' => ['users.manage', 'users.edit-personal'],
        'account:password' => ['users.manage', 'users.security'],
        'wallet:read' => ['wallet.manage'],
        'wallet:credit' => ['wallet.manage'],
        'wallet:debit' => ['wallet.manage'],
        'wallet:set' => ['wallet.manage'],
    ];

    public function available(User $actor): array
    {
        return array_keys(
            array_filter(
                self::SCOPES,
                fn ($permissions) => collect(['admin.access', 'servers.manage', ...$permissions])->every(
                    fn ($permission) => $actor->hasPermission($permission),
                ),
            ),
        );
    }

    public function issue(Server $server, User $actor, string $name, array $scopes, int $days = 30): array
    {
        return app(OperationLock::class)->shared(
            fn () => DB::transaction(function () use ($server, $actor, $name, $scopes, $days) {
                $server = Server::query()->lockForUpdate()->findOrFail($server->id);
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->server($server);
                if (
                    $scopes === [] ||
                    count($scopes) !== count(array_unique($scopes)) ||
                    array_diff($scopes, $this->available($actor)) ||
                    $days < 1 ||
                    $days > 90 ||
                    trim($name) === '' ||
                    mb_strlen($name) > 120
                ) {
                    throw ValidationException::withMessages([
                        'scopes' => __('admin.servers.accounts.invalid_scopes'),
                    ]);
                }
                foreach ($scopes as $scope) {
                    $this->actor($actor, $scope);
                }
                if ($server->accountTokens()->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= 10) {
                    throw ValidationException::withMessages(['scopes' => __('admin.servers.accounts.token_limit')]);
                }
                $secret = bin2hex(random_bytes(32));
                /** @var LinkAccountToken $token */
                $token = $server->accountTokens()->create([
                    'id' => (string) Str::uuid(),
                    'actor_id' => $actor->id,
                    'name' => trim($name),
                    'token_hash' => hash('sha256', $secret),
                    'security_stamp' => app(ApiTokens::class)->stamp($actor),
                    'scopes' => $scopes,
                    'expires_at' => now()->addDays($days),
                ]);
                Audit::record(
                    'link.account_token_issued',
                    ['id' => $token->id, 'server' => $server->id, 'scopes' => $scopes],
                    $actor,
                );

                return [$token, $token->id.'|'.$secret];
            }, 3),
        );
    }

    /**
     * Runs the callback atomically, rolling back its writes before propagating a failure.
     *
     * @throws Throwable
     */
    public function with(string $raw, string $scope, Closure $operation): mixed
    {
        if (!preg_match('/^([a-f0-9-]{36})\|([a-f0-9]{64})$/D', $raw, $parts)) {
            throw new HttpException(401);
        }

        return app(OperationLock::class)->shared(
            fn () => DB::transaction(function () use ($parts, $scope, $operation) {
                $token = LinkAccountToken::query()->lockForUpdate()->find($parts[1]);
                if (
                    !$token ||
                    $token->revoked_at ||
                    !$token->expires_at->isFuture() ||
                    !hash_equals($token->token_hash, hash('sha256', $parts[2]))
                ) {
                    throw new HttpException(401);
                }
                if (!isset(self::SCOPES[$scope]) || !in_array($scope, $token->scopes, true)) {
                    throw new HttpException(403);
                }
                /** @var Server|null $server */
                $server = $token->server()->lockForUpdate()->first();
                /** @var User|null $actor */
                $actor = $token->actor()->lockForUpdate()->first();
                if (!$server || !$actor || !hash_equals($token->security_stamp, app(ApiTokens::class)->stamp($actor))) {
                    throw new HttpException(401);
                }
                $this->server($server);
                $this->actor($actor, $scope);
                $result = $operation($server, $actor, $token);
                if (!$token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
                    $token->update(['last_used_at' => now()]);
                }

                return $result;
            }, 3),
        );
    }

    public function revoke(Server $server, string $id, User $actor): void
    {
        app(OperationLock::class)->shared(
            fn () => DB::transaction(function () use ($server, $id, $actor) {
                $token = $server->accountTokens()->lockForUpdate()->findOrFail($id);
                Server::query()->lockForUpdate()->findOrFail($server->id);
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                if ($actor->isBanned() || $actor->anonymized_at || $actor->import_locked) {
                    throw new HttpException(403);
                }
                if (!$actor->hasPermission('admin.access') || !$actor->hasPermission('servers.manage')) {
                    throw new HttpException(403);
                }
                $token->update(['revoked_at' => now()]);
                Audit::record('link.account_token_revoked', ['id' => $id, 'server' => $server->id], $actor);
            }, 3),
        );
    }

    private function server(Server $server): void
    {
        if (
            !$server->enabled ||
            $server->connection !== 'kraftlink' ||
            app(GameRegistry::class)->activeId() !== $server->game ||
            !isset(app(GameRegistry::class)->linkableGames()[$server->game]['connections']['kraftlink'])
        ) {
            throw new HttpException(403);
        }
    }

    private function actor(User $actor, string $scope): void
    {
        if (
            $actor->isBanned() ||
            $actor->anonymized_at ||
            $actor->registration_pending ||
            $actor->import_locked ||
            $actor->force_password_reset
        ) {
            throw new HttpException(403);
        }
        foreach (['admin.access', 'servers.manage', ...self::SCOPES[$scope]] as $permission) {
            if (!$actor->hasPermission($permission)) {
                throw new HttpException(403);
            }
        }
    }
}
