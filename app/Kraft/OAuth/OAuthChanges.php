<?php

namespace App\Kraft\OAuth;

use App\Models\Accounts\ApiAccessToken;
use App\Models\Setting;
use App\Jobs\SendOAuthRecovery;
use App\Kraft\Audit;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Auth\Passkeys;
use App\Kraft\Auth\PermissionCatalog;
use App\Kraft\Settings;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Events\OAuthConfigurationValidating;
use App\Events\ExtensionStateChanging;
use App\Models\OAuth\OAuthAccount;
use JsonException;

class OAuthChanges
{
    public function __construct(private OAuthRegistry $registry, private Settings $settings)
    {
    }

    public function usable(User $user, ?string $mode = null, ?array $providers = null, bool $includePasskeys = true): bool
    {
        if ($user->isServiceAccount()) {
            return false;
        }
        $mode ??= $this->settings->get('auth.mode', 'mixed');
        $providers ??= $this->registry->enabled();
        $rpId = $includePasskeys ? app(Passkeys::class)->enabledHost() : null;

        return ($mode !== 'external' && (
            $user->hasLocalPassword() ||
            ($rpId !== null && $user->passkeys()->where('rp_id', $rpId)->exists())
        )) ||
            ($mode !== 'local' && $user->oauthAccounts()->whereIn('provider', $providers)->exists());
    }

    public function extensionStateChanging(ExtensionStateChanging $event): void
    {
        $namespace = $event->manifest['namespace'] ?? null;
        if ($event->enabled || !$namespace) {
            return;
        }
        $adapters = config('oauth.adapters', []);
        $providers = array_filter($this->registry->enabled(), function (string $id) use ($namespace, $adapters) {
            $adapter = $adapters[$this->registry->metadata($id)['adapter'] ?? ''] ?? '';

            return str_starts_with($adapter, rtrim($namespace, '\\').'\\');
        });
        if ($providers && OAuthAccount::query()->whereIn('provider', $providers)->exists()) {
            throw ValidationException::withMessages(['extension' => __('admin.integrations.oauth.extension_in_use')]);
        }
    }

    public function plan(array $proposal): array
    {
        $operation = $proposal['operation'];
        $oldMode = $this->settings->get('auth.mode', 'mixed');
        $global = $operation === 'global' ? (bool) $proposal['enabled'] : $this->registry->globallyEnabled();
        $mode = $operation === 'policy' ? $proposal['mode'] : $oldMode;
        $states = [];
        foreach ($this->registry->all() as $id) {
            $states[$id] = (bool) $this->settings->get('oauth.'.$id.'.enabled', false);
        }
        $previousEnabled = array_values(array_filter(array_keys($states), fn ($id) => $states[$id]));
        if ($operation === 'provider') {
            if (!isset($states[$proposal['provider']])) {
                abort(404);
            }
            $states[$proposal['provider']] = false;
        }
        $enabled = $global
            ? array_values(
                array_filter(array_keys($states), fn ($id) => $states[$id] && $this->registry->configured($id)),
            )
            : [];
        $oldEnabled = $oldMode === 'local' || !$this->registry->globallyEnabled() ? [] : $previousEnabled;
        if (
            $mode === 'external' &&
            $operation !== 'policy' &&
            (!$global || $this->atRisk($mode, $enabled, $oldMode, $oldEnabled)->exists())
        ) {
            $mode = 'mixed';
        }
        $effective = $mode === 'local' ? [] : $enabled;
        event(new OAuthConfigurationValidating($enabled));
        $recoveries = [];
        $blocked = [];
        $privileged = ['*', ...app(PermissionCatalog::class)->privileged()];
        $adminRoles = Role::all()
            ->filter(fn ($role) => count(array_intersect($role->effectivePermissions(), $privileged)) > 0)
            ->modelKeys();
        $administrators = User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('roles.id', $adminRoles))
            ->pluck('id')
            ->flip();
        $fingerprint = hash_init('sha256');
        foreach (
            $this->atRisk($mode, $effective, $oldMode, $oldEnabled, $operation === 'policy' && $mode === 'external')
                ->orderBy('id')
                ->cursor() as $user
        ) {
            /** @var User $user */
            hash_update(
                $fingerprint,
                implode('|', [
                    $user->id,
                    $user->auth_session_version,
                    $user->email,
                    $user->email_verified_at?->getTimestamp(),
                    $user->local_login_enabled,
                    $user->oauth_recovery_required_at?->getTimestamp(),
                ]),
            );
            if (
                $mode === 'external' ||
                $administrators->has($user->id) ||
                !$user->hasVerifiedEmail() ||
                !filter_var($user->email, FILTER_VALIDATE_EMAIL) ||
                str_ends_with($user->email, '.invalid')
            ) {
                $blocked[] = $user->id;
            } else {
                $recoveries[] = $user->id;
            }
        }
        $removed = array_values(array_diff($oldEnabled, $effective));
        $rpId = app(Passkeys::class)->enabledHost();
        $revoked = User::members()
            ->whereNull('anonymized_at')
            ->where(function (Builder $query) use ($removed, $mode, $oldMode, $rpId) {
                $query->whereHas('oauthAccounts', fn (Builder $accounts) => $accounts->whereIn('provider', $removed));
                if ($mode === 'external' && $oldMode !== 'external') {
                    $query->orWhere('local_login_enabled', true);
                    if ($rpId !== null) {
                        $query->orWhereHas('passkeys', fn (Builder $keys) => $keys->where('rp_id', $rpId));
                    }
                }
            })
            ->orderBy('id')
            ->get(['id', 'auth_session_version']);
        foreach ($revoked as $user) {
            /** @var User $user */
            hash_update($fingerprint, 'revoke:'.$user->id.':'.$user->auth_session_version);
        }
        $blockedAdministrators = User::members()
            ->whereNull('anonymized_at')
            ->where('import_locked', false)
            ->whereIn('id', $administrators->keys())
            ->get()
            ->filter(fn ($user) => !$user->isBanned() && !$this->usable($user, $mode, $enabled))
            ->modelKeys();
        $blocked = array_values(array_unique([...$blocked, ...$blockedAdministrators]));

        return [
            'proposal' => $proposal,
            'mode' => $mode,
            'global' => $global,
            'states' => $states,
            'enabled' => $enabled,
            'recoveries' => $recoveries,
            'blocked' => $blocked,
            'revoked' => $revoked->pluck('id')->all(),
            'fingerprint' => hash_final($fingerprint),
            'mode_changed' => $mode !== $oldMode,
        ];
    }

    private function atRisk(
        string $mode,
        array $enabled,
        string $oldMode,
        array $oldEnabled,
        bool $all = false,
    ): Builder {
        $rpId = app(Passkeys::class)->enabledHost();
        $query = User::members()
            ->whereNull('anonymized_at')
            ->where('import_locked', false)
            ->withoutActiveBan();
        if ($mode !== 'external') {
            $query->where(fn (Builder $local) => $local->where('local_login_enabled', false)
                ->orWhereNull('password')->orWhere('password', ''));
            if ($rpId !== null) {
                $query->whereDoesntHave('passkeys', fn (Builder $keys) => $keys->where('rp_id', $rpId));
            }
        }
        if ($mode !== 'local') {
            $query->whereDoesntHave('oauthAccounts', fn (Builder $accounts) => $accounts->whereIn('provider', $enabled));
        }
        if ($all) {
            return $query;
        }

        return $query->where(function (Builder $oldAccess) use ($oldMode, $oldEnabled, $rpId) {
            $oldAccess->whereHas('oauthAccounts', fn (Builder $accounts) => $accounts->whereIn('provider', $oldEnabled));
            $oldAccess->orWhereNotNull('oauth_recovery_required_at');
            if ($oldMode !== 'external') {
                $oldAccess->orWhere(fn (Builder $local) => $local->where('local_login_enabled', true)
                    ->whereNotNull('password')->where('password', '!=', ''));
                if ($rpId !== null) {
                    $oldAccess->orWhereHas('passkeys', fn (Builder $keys) => $keys->where('rp_id', $rpId));
                }
            }
        });
    }

    /**
     * @throws JsonException
     */
    public function token(array $plan, User $actor): string
    {
        return Crypt::encryptString(
            json_encode(
                [
                    'actor' => $actor->id,
                    'at' => time(),
                    'revision' => (int) $this->settings->get('oauth.revision', 0),
                    'proposal' => $plan['proposal'],
                    'fingerprint' => $plan['fingerprint'],
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function apply(string $token, User $actor): array
    {
        try {
            $proof = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['oauth' => __('admin.integrations.oauth.preview_expired')]);
        }
        if (
            ($proof['actor'] ?? null) !== $actor->id ||
            ($proof['at'] ?? 0) < time() - 600 ||
            ($proof['at'] ?? 0) > time() + 30 ||
            !in_array($proof['proposal']['operation'] ?? null, ['provider', 'global'], true)
        ) {
            throw ValidationException::withMessages(['oauth' => __('admin.integrations.oauth.preview_expired')]);
        }

        return app(InGameSignInPolicy::class)->withStableConfiguration(fn () => DB::transaction(function () use ($proof, $actor) {
            $revision = $this->lockRevision();
            if ($revision !== $proof['revision']) {
                throw ValidationException::withMessages([
                    'oauth' => __('admin.integrations.oauth.preview_expired'),
                ]);
            }
            $plan = $this->plan($proof['proposal']);
            if (!hash_equals($proof['fingerprint'], $plan['fingerprint'])) {
                throw ValidationException::withMessages([
                    'oauth' => __('admin.integrations.oauth.preview_changed'),
                ]);
            }

            return $this->persist($plan, $actor, $revision, 'oauth');
        }, 3));
    }

    // The account settings controller already holds the shared configuration lock and database transaction.
    public function applyMode(string $mode, User $actor): array
    {
        $revision = $this->lockRevision();
        $this->settings->refresh();

        return $this->persist($this->plan(['operation' => 'policy', 'mode' => $mode]), $actor, $revision, 'auth_mode');
    }

    private function lockRevision(): int
    {
        Setting::query()->firstOrCreate(['key' => 'oauth.revision'], ['value' => 0]);

        return (int) Setting::query()->where('key', 'oauth.revision')->lockForUpdate()->value('value');
    }

    private function persist(array $plan, User $actor, int $revision, string $field): array
    {
        if ($plan['blocked'] || !$this->usable($actor, $plan['mode'], $plan['enabled'])) {
            throw ValidationException::withMessages([$field => __('admin.integrations.oauth.change_blocked')]);
        }
        $this->settings->set('oauth.enabled', $plan['global']);
        $this->settings->set('auth.mode', $plan['mode']);
        foreach ($plan['states'] as $id => $enabled) {
            $this->settings->set('oauth.'.$id.'.enabled', $enabled);
        }
        $this->settings->set('oauth.revision', $revision + 1);
        User::query()
            ->whereIn('id', $plan['recoveries'])
            ->update(['oauth_recovery_required_at' => now()]);
        foreach (array_chunk($plan['revoked'], 500) as $ids) {
            User::query()
                ->whereIn('id', $ids)
                ->update([
                    'auth_session_version' => DB::raw('auth_session_version + 1'),
                    'remember_token' => null,
                ]);
            ApiAccessToken::query()
                ->whereIn('user_id', $ids)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        }
        SendOAuthRecovery::requestMany($plan['recoveries']);
        Audit::record('oauth.access_changed', [
            'operation' => $plan['proposal']['operation'],
            'provider' => $plan['proposal']['provider'] ?? null,
            'recoveries' => count($plan['recoveries']),
            'revoked' => count($plan['revoked']),
            'mode' => $plan['mode'],
        ]);

        return $plan;
    }

    public function assertCredentialChange(User $actor, string $provider): void
    {
        $remaining = array_values(array_diff($this->registry->enabled(), [$provider]));
        $privileged = ['*', ...app(PermissionCatalog::class)->privileged()];
        $roleIds = Role::all()
            ->filter(fn ($role) => count(array_intersect($role->effectivePermissions(), $privileged)) > 0)
            ->modelKeys();
        $users = User::members()
            ->whereNull('anonymized_at')
            ->where('import_locked', false)
            ->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $roleIds))
            ->get();
        if (
            $users->contains(fn (User $user) => !$user->isBanned() && !$this->usable($user, null, $remaining)) ||
            !$this->usable($actor, null, $remaining)
        ) {
            throw ValidationException::withMessages(['oauth' => __('admin.integrations.oauth.credentials_lockout')]);
        }
    }
}
