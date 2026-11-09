<?php

namespace App\Kraft\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Events\AuthenticatingUser;
use App\Kraft\Audit;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;
use App\Models\Accounts\ApiAccessToken;
use App\Models\Accounts\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Random\RandomException;

class ApiTokens
{
    public function issue(User $user, string $name, array $scopes, int $minutes, string $verifiedStamp): array
    {
        if ($user->isServiceAccount()) {
            throw new HttpException(403);
        }
        if (!$scopes || count($scopes) > 100 || array_diff($scopes, app(ApiScopes::class)->effective($user, $scopes))) {
            throw ValidationException::withMessages(['scopes' => __('admin.integrations.auth_api.invalid_scope')]);
        }

        return DB::transaction(function () use ($user, $name, $scopes, $minutes, $verifiedStamp) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->isServiceAccount()) {
                throw new HttpException(403);
            }
            if (!hash_equals($verifiedStamp, $this->stamp($user))) {
                throw new HttpException(401, __('admin.integrations.auth_api.challenge_expired'));
            }
            $this->assertEligible($user);
            if (array_diff($scopes, app(ApiScopes::class)->effective($user, $scopes))) {
                throw ValidationException::withMessages([
                    'scopes' => __('admin.integrations.auth_api.invalid_scope'),
                ]);
            }
            if ($user->apiAccessTokens()->active()->count() >= 50) {
                throw ValidationException::withMessages(['token' => __('admin.integrations.auth_api.token_limit')]);
            }
            $maximum = max(15, min(43200, (int) app(Settings::class)->get('auth.api.lifetime', 1440)));
            $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->save();

            return $this->create($user, $name, $scopes, now()->addMinutes(max(15, min($maximum, $minutes))));
        }, 3);
    }

    /**
     * @throws JsonException
     */
    public function authenticate(string $raw, ?string $scope = null): array
    {
        if (!preg_match('/^([a-f0-9-]{36})\|([a-f0-9]{64})$/i', $raw, $parts)) {
            throw new HttpException(401, __('admin.integrations.auth_api.invalid_token'));
        }
        $token = ApiAccessToken::with('user')->find($parts[1]);
        if (!$token || !hash_equals($token->token_hash, hash('sha256', $parts[2])) || !$this->valid($token)) {
            throw new HttpException(401, __('admin.integrations.auth_api.invalid_token'));
        }
        $this->assertEligible($token->user);
        $token->scopes = app(ApiScopes::class)->effective($token->user, $token->scopes);
        if ($token->user->isServiceAccount()) {
            $token->user->restrictApiPermissions($token->scopes);
        }
        if ($scope && !in_array($scope, $token->scopes, true)) {
            throw new HttpException(403, __('admin.integrations.auth_api.insufficient_scope'));
        }
        if (!$token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            ApiAccessToken::query()
                ->whereKey($token->id)
                ->update(['last_used_at' => now()]);
            $token->last_used_at = now();
        }

        return [$token, $token->user];
    }

    public function rotate(ApiAccessToken $token): array
    {
        return DB::transaction(function () use ($token) {
            $current = ApiAccessToken::with('user')->lockForUpdate()->findOrFail($token->id);
            if (!$this->valid($current)) {
                throw new HttpException(401, __('admin.integrations.auth_api.invalid_token'));
            }
            if ($current->user->isServiceAccount()) {
                throw new HttpException(403, __('service_accounts.admin_rotation_required'));
            }
            $this->assertEligible($current->user);
            $scopes = app(ApiScopes::class)->effective($current->user, $current->scopes);
            if (!$scopes) {
                throw new HttpException(403, __('admin.integrations.auth_api.insufficient_scope'));
            }
            $current->forceFill(['revoked_at' => now()])->save();

            return $this->create($current->user, $current->name, $scopes, $current->expires_at);
        }, 3);
    }

    /**
     * @throws JsonException
     */
    public function stamp(User $user): string
    {
        if ($user->isServiceAccount()) {
            return hash_hmac(
                'sha256',
                json_encode(['service', $user->id, $user->auth_session_version], JSON_THROW_ON_ERROR),
                (string) config('app.key'),
            );
        }

        return hash_hmac(
            'sha256',
            implode('|', [
                $user->getAuthPassword(),
                $user->email,
                $user->getRawOriginal('two_factor_secret') ?? '',
                $user->two_factor_confirmed_at?->getTimestamp() ?? '',
                (int) $user->local_login_enabled,
                $user->auth_session_version,
            ]),
            (string) config('app.key'),
        );
    }

    public function assertEligible(User $user, bool $dispatch = true): void
    {
        if ($user->isServiceAccount()) {
            /** @var Settings $settings */
            $settings = app(Settings::class);
            if (!$settings->get('auth.api.enabled', false)) {
                throw new HttpException(403, __('admin.integrations.auth_api.disabled'));
            }
            if ($user->service_disabled_at || $user->import_locked || $user->anonymized_at || $user->isBanned()) {
                throw new HttpException(403);
            }

            return;
        }
        if (
            !app(AuthPolicy::class)->allowsLocal() ||
            !$user->local_login_enabled ||
            $user->registration_pending ||
            $user->import_locked ||
            $user->anonymized_at ||
            $user->force_password_reset ||
            $user->isBanned()
        ) {
            throw new HttpException(403, __('site.auth.messages.invalid_credentials'));
        }
        if (app(AccountPolicy::class)->requiresVerifiedEmail() && !$user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => __('admin.integrations.auth_api.email_required')]);
        }
        if ($dispatch) {
            event(new AuthenticatingUser($user, 'api'));
        }
        app(GameRegistry::class)->assertUserCanAuthenticate($user);
    }

    /**
     * @throws JsonException
     */
    private function valid(ApiAccessToken $token): bool
    {
        return $token->user &&
            !$token->revoked_at &&
            $token->expires_at->isFuture() &&
            hash_equals($token->security_stamp, $this->stamp($token->user));
    }

    public function issueService(User $actor, User $service, string $name, array $scopes, int $minutes): array
    {
        if ($actor->isServiceAccount()) {
            throw new HttpException(403);
        }

        return DB::transaction(function () use ($actor, $service, $name, $scopes, $minutes): array {
            $actor = User::members()->lockForUpdate()->findOrFail($actor->id);
            $service = User::services()->lockForUpdate()->findOrFail($service->id);
            app(ServiceAccounts::class)->assertCanManage($actor, $service);
            $this->assertEligible($service);
            if (
                !$scopes ||
                count($scopes) > 100 ||
                !array_is_list($scopes) ||
                count(array_filter($scopes, 'is_string')) !== count($scopes) ||
                array_diff($scopes, app(ApiScopes::class)->effective($service, $scopes))
            ) {
                throw ValidationException::withMessages([
                    'scopes' => __('admin.integrations.auth_api.invalid_scope'),
                ]);
            }
            if (trim($name) === '' || mb_strlen($name) > 120) {
                throw ValidationException::withMessages(['name' => __('service_accounts.invalid_name')]);
            }
            if ($service->apiAccessTokens()->active()->count() >= 50) {
                throw ValidationException::withMessages(['token' => __('admin.integrations.auth_api.token_limit')]);
            }
            $maximum = max(15, min(43200, (int) app(Settings::class)->get('auth.api.lifetime', 1440)));
            if ($minutes < 15 || $minutes > $maximum) {
                throw ValidationException::withMessages(['lifetime' => __('service_accounts.invalid_lifetime')]);
            }

            return $this->create($service, trim($name), $scopes, now()->addMinutes($minutes), $actor);
        }, 3);
    }

    public function rotateService(User $actor, User $service, ApiAccessToken $token): array
    {
        if ($actor->isServiceAccount()) {
            throw new HttpException(403);
        }

        return DB::transaction(function () use ($actor, $service, $token): array {
            $actor = User::members()->lockForUpdate()->findOrFail($actor->id);
            $service = User::services()->lockForUpdate()->findOrFail($service->id);
            app(ServiceAccounts::class)->assertCanManage($actor, $service);
            $this->assertEligible($service);
            /** @var ApiAccessToken $current */
            $current = $service->apiAccessTokens()->lockForUpdate()->findOrFail($token->id);
            $current->setRelation('user', $service);
            if (!$this->valid($current)) {
                throw new HttpException(409, __('admin.integrations.auth_api.invalid_token'));
            }
            $scopes = app(ApiScopes::class)->effective($service, $current->scopes);
            if (!$scopes) {
                throw new HttpException(403, __('admin.integrations.auth_api.insufficient_scope'));
            }
            $current->update(['revoked_at' => now()]);

            $issued = $this->create($service, $current->name, $scopes, $current->expires_at, $actor);
            Audit::record(
                'service_account.token_rotated',
                ['principal_id' => $service->id, 'previous_token_id' => $current->id, 'token_id' => $issued[0]->id],
                $actor,
            );

            return $issued;
        }, 3);
    }

    /**
     * @throws RandomException
     * @throws JsonException
     */
    private function create(
        User $user,
        string $name,
        array $scopes,
        CarbonInterface $expires,
        ?User $actor = null,
    ): array {
        $secret = bin2hex(random_bytes(32));
        /** @var ApiAccessToken $token */
        $token = $user->apiAccessTokens()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'token_hash' => hash('sha256', $secret),
            'scopes' => array_values(array_unique($scopes)),
            'security_stamp' => $this->stamp($user),
            'expires_at' => $expires,
            'created_at' => now(),
        ]);
        Audit::record(
            'auth.api_token_issued',
            [
                'id' => $token->id,
                'name' => $name,
                'scopes' => $token->scopes,
                'principal_id' => $user->id,
                'account_type' => $user->account_type,
            ],
            $actor ?? $user,
        );

        return [$token, $token->id.'|'.$secret];
    }
}
