<?php

namespace App\Kraft\OAuth\Discord;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\OAuth\OAuthAccount;
use App\Exceptions\RoleApiFailure;
use App\Kraft\Audit;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Settings;
use App\Kraft\System\OperationLock;
use App\Models\OAuth\Discord\RoleConnection;
use App\Models\OAuth\Discord\RoleRevocation;
use App\Models\Accounts\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as ExternalUser;
use Laravel\Socialite\Two\User as OAuth2User;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use Throwable;
use JsonException;
use Illuminate\Contracts\Cache\LockTimeoutException;

class RoleConnections
{
    public function provider(): ?string
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $provider = $settings->get('discord.roles.provider');

        return is_string($provider) && (app(OAuthRegistry::class)->metadata($provider)['adapter'] ?? null) === 'discord'
            ? $provider
            : null;
    }

    /**
     * @throws JsonException
     */
    public function definitionHash(): string
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);

        return hash(
            'sha256',
            json_encode(
                [
                    'application' => $settings->get('oauth.'.$this->provider().'.client_id'),
                    'records' => app(RoleMetadata::class)->records(),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function ready(): bool
    {
        try {
            return app(Settings::class)->get('discord.roles.enabled', false) &&
                in_array($this->provider(), app(OAuthRegistry::class)->enabled(), true) &&
                hash_equals(
                    $this->definitionHash(),
                    (string) app(Settings::class)->get('discord.roles.registered_hash', ''),
                );
        } catch (Throwable) {
            return false;
        }
    }

    public function driver(string $provider): DiscordProvider
    {
        if (!$this->ready() || $this->provider() !== $provider) {
            throw new HttpException(403);
        }
        $credentials = app(RoleTokens::class)->currentClient($provider);

        /** @var DiscordProvider $driver */
        $driver = app(OAuthRegistry::class)
            ->adapter($provider)
            ->provider(
                $credentials + [
                    'redirect' => route('account.discord.roles.callback'),
                    'guzzle' => ['timeout' => 15, 'connect_timeout' => 5],
                ],
            );

        return $driver->setScopes(['identify', 'role_connections.write']);
    }

    /**
     * @param  OAuth2User  $identity
     *
     * @throws LockTimeoutException
     */
    public function accept(User $user, string $provider, ExternalUser $identity): RoleConnection
    {
        return app(OperationLock::class)->shared(fn () => $this->acceptLocked($user, $provider, $identity));
    }

    /**
     * @param  OAuth2User  $identity
     *
     * @throws LockTimeoutException
     */
    private function acceptLocked(User $user, string $provider, ExternalUser $identity): RoleConnection
    {
        if (!$this->ready() || $this->provider() !== $provider || !$this->active($user)) {
            throw new HttpException(403);
        }
        $rawSubject = $identity->getId();
        if (!is_string($rawSubject) && !is_int($rawSubject)) {
            throw ValidationException::withMessages([
                'discord_roles' => __('admin.integrations.discord.authorization_missing'),
            ]);
        }
        $subject = (string) $rawSubject;
        $scopes = $identity->approvedScopes ?? [];
        if (
            !preg_match('/^[0-9]{5,32}$/D', $subject) ||
            !is_array($scopes) ||
            !in_array('role_connections.write', $scopes, true) ||
            !is_string($identity->token ?? null) ||
            $identity->token === '' ||
            strlen($identity->token) > 8192 ||
            !is_numeric($identity->expiresIn ?? null) ||
            (int) $identity->expiresIn < 1 ||
            (int) $identity->expiresIn > 5_184_000 ||
            !is_string($identity->refreshToken ?? '') ||
            strlen($identity->refreshToken ?? '') > 8192 ||
            preg_match('/[\x00-\x1F\x7F]/', $identity->token.($identity->refreshToken ?? ''))
        ) {
            throw ValidationException::withMessages([
                'discord_roles' => __('admin.integrations.discord.authorization_missing'),
            ]);
        }
        $client = app(RoleTokens::class)->currentClient($provider);
        $application = $client['client_id'];
        /** @var OAuthAccount|null $linked */
        $linked = $user->oauthAccounts()->where('provider', $provider)->first();
        if ($linked && $linked->subject !== $subject) {
            throw ValidationException::withMessages([
                'discord_roles' => __('admin.integrations.discord.account_mismatch'),
            ]);
        }
        if (
            RoleConnection::query()
                ->where('application_id', $application)
                ->where('subject', $subject)
                ->where('user_id', '!=', $user->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'discord_roles' => __('admin.integrations.discord.already_claimed'),
            ]);
        }
        $credentials = [
            'access_token' => $identity->token,
            'refresh_token' => $identity->refreshToken ?: null,
            'expires_at' => now()->addSeconds((int) $identity->expiresIn)->toIso8601String(),
            'client_credentials' => $client,
        ];
        $this->repairRevocations($user, $application, $subject, $credentials);

        return Cache::lock($this->lockKey($user->id, $provider), 60)->block(5, function () use (
            $user,
            $provider,
            $application,
            $subject,
            $linked,
            $credentials,
        ) {
            $save = fn () => DB::transaction(function () use (
                $user,
                $provider,
                $application,
                $subject,
                $linked,
                $credentials,
            ) {
                $current = $user
                    ->discordRoleConnections()
                    ->where('provider', $provider)
                    ->lockForUpdate()
                    ->first();
                if ($current && ($current->application_id !== $application || $current->subject !== $subject)) {
                    throw ValidationException::withMessages([
                        'discord_roles' => __('admin.integrations.discord.disconnect_first'),
                    ]);
                }
                $connection = $user->discordRoleConnections()->updateOrCreate(
                    ['provider' => $provider],
                    [
                        'application_id' => $application,
                        'subject' => $subject,
                        'oauth_account_id' => $linked?->id,
                        'access_token' => $credentials['access_token'],
                        'refresh_token' => $credentials['refresh_token'],
                        'client_credentials' => $credentials['client_credentials'],
                        'expires_at' => $credentials['expires_at'],
                        'consented_at' => now(),
                        'fields' => app(RoleMetadata::class)->selected(),
                        'metadata_hash' => null,
                        'next_sync_at' => now(),
                        'last_error' => null,
                        'attempts' => 0,
                    ],
                );
                Audit::record('discord.roles.consented', ['id' => $connection->id, 'provider' => $provider], $user);

                return $connection;
            }, 3);
            $existing = $user->discordRoleConnections()->where('provider', $provider)->value('id');

            return $existing ? Cache::lock('discord-role-connection:'.$existing, 60)->block(5, $save) : $save();
        });
    }

    public function revoke(RoleConnection $connection, string $reason): ?RoleRevocation
    {
        return DB::transaction(function () use ($connection, $reason) {
            $connection = RoleConnection::query()->whereKey($connection->id)->lockForUpdate()->first();
            if (!$connection) {
                return null;
            }
            try {
                $credentials = $this->credentials($connection);
            } catch (Throwable) {
                $credentials = null;
            }
            $revocation = RoleRevocation::query()->firstOrCreate(
                ['connection_id' => $connection->id],
                [
                    'id' => (string) Str::uuid(),
                    'user_id' => $connection->user_id,
                    'provider' => $connection->provider,
                    'application_id' => $connection->application_id,
                    'subject_hash' => $this->subjectHash($connection->application_id, $connection->subject),
                    'credentials' => $credentials,
                    'reason' => $reason,
                    'state' => $credentials ? 'pending' : 'authorization_required',
                    'last_error' => $credentials ? null : 'authorization_expired',
                    'next_attempt_at' => now(),
                ],
            );
            $connection->delete();

            return $revocation;
        }, 3);
    }

    public function revokeUser(int $userId, string $reason): void
    {
        RoleConnection::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->each(fn ($connection) => $this->revoke($connection, $reason));
    }

    public function due(?int $userId = null): void
    {
        RoleConnection::query()
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->update([
                'next_sync_at' => now(),
            ]);
    }

    public function reconcileUser(int $id): void
    {
        $user = User::query()->find($id);
        if (!$user || !$this->active($user)) {
            $this->revokeUser($id, 'account_inactive');
        } else {
            $this->due($id);
        }
    }

    public function reconcileConfiguration(): void
    {
        app(Settings::class)->refresh();
        $this->due();
        foreach (RoleConnection::query()->orderBy('id')->limit(100)->get() as $connection) {
            /** @var RoleConnection $connection */
            $user = User::query()->find($connection->user_id);
            if (!$user || !$this->eligible($connection, $user)) {
                $this->revoke($connection, 'configuration_changed');
            }
        }
    }

    public function process(int $limit = 100): array
    {
        return app(OperationLock::class)->shared(fn () => $this->processLocked($limit));
    }

    private function processLocked(int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $result = ['revocations' => 0, 'connections' => 0];
        foreach (
            RoleRevocation::query()
                ->where('state', 'pending')
                ->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')
                ->limit($limit)
                ->pluck('id') as $id
        ) {
            $this->remove($id);
            $result['revocations']++;
        }
        $remaining = $limit - $result['revocations'];
        if ($remaining > 0) {
            foreach (
                RoleConnection::query()
                    ->where('next_sync_at', '<=', now())
                    ->orderBy('next_sync_at')
                    ->limit($remaining)
                    ->pluck('id') as $id
            ) {
                $this->sync($id);
                $result['connections']++;
            }
        }

        return $result;
    }

    public function sync(int $id): bool
    {
        return app(OperationLock::class)->shared(fn () => $this->syncLocked($id));
    }

    private function syncLocked(int $id): bool
    {
        app(Settings::class)->refresh();
        $connection = RoleConnection::query()->find($id);
        if (!$connection) {
            return false;
        }
        $lock = Cache::lock('discord-role-connection:'.$id, 60);
        if (!$lock->get()) {
            return false;
        }
        try {
            $connection = $connection->fresh();
            if (!$connection) {
                return false;
            }
            $user = User::query()->find($connection->user_id);
            if (!$user || !$this->eligible($connection, $user)) {
                $this->revoke(
                    $connection,
                    !$user || !$this->active($user) ? 'account_inactive' : 'configuration_changed',
                );

                return false;
            }
            try {
                $payload = [
                    'platform_name' => mb_substr(
                        strip_tags((string) app(Settings::class)->get('site.name', 'KraftInk')),
                        0,
                        50,
                    ),
                    'platform_username' => $user->username,
                    'metadata' => app(RoleMetadata::class)->values($user),
                ];
                $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                if ($hash === $connection->metadata_hash && $connection->last_synced_at?->isAfter(now()->subHour())) {
                    $connection->update(['next_sync_at' => now()->addMinute(), 'last_error' => null]);

                    return true;
                }
                $credentials = app(RoleTokens::class)->valid($this->credentials($connection), $connection->provider);
                $this->saveTokens($connection, $credentials);
                $response = app(DiscordRoleApi::class)->update(
                    $connection->application_id,
                    $credentials['access_token'],
                    $payload,
                );
                if ($response->status() === 401) {
                    $credentials = app(RoleTokens::class)->valid($credentials, $connection->provider, true);
                    $this->saveTokens($connection, $credentials);
                    $response = app(DiscordRoleApi::class)->update(
                        $connection->application_id,
                        $credentials['access_token'],
                        $payload,
                    );
                }
                app(RoleTokens::class)->ensure($response);
                if (!RoleConnection::query()->whereKey($connection->id)->exists()) {
                    return false;
                }
                $connection->update([
                    'metadata_hash' => $hash,
                    'last_synced_at' => now(),
                    'next_sync_at' => now()->addMinute(),
                    'last_error' => null,
                    'attempts' => 0,
                ]);

                return true;
            } catch (RoleApiFailure $exception) {
                if ($exception->requiresAuthorization || $exception->reason === 'configuration_error') {
                    $this->revoke($connection, $exception->reason);
                } else {
                    $connection->update([
                        'last_error' => $exception->reason,
                        'attempts' => $connection->attempts + 1,
                        'next_sync_at' => now()->addSeconds($exception->retryAfter),
                    ]);
                }
            } catch (ConnectionException) {
                $connection->update([
                    'last_error' => 'unavailable',
                    'attempts' => $connection->attempts + 1,
                    'next_sync_at' => now()->addMinutes(5),
                ]);
            } catch (Throwable) {
                $this->revoke($connection, 'criteria_unavailable');
            }

            return false;
        } finally {
            $lock->release();
        }
    }

    public function remove(string $id): bool
    {
        return app(OperationLock::class)->shared(fn () => $this->removeCoordinated($id));
    }

    private function removeCoordinated(string $id): bool
    {
        $revocation = RoleRevocation::query()->find($id);
        if (!$revocation || $revocation->state === 'completed') {
            return true;
        }
        $lock = Cache::lock('discord-role-connection:'.$revocation->connection_id, 60);
        if (!$lock->get()) {
            return false;
        }
        try {
            $revocation = $revocation->fresh();
            if (!$revocation || $revocation->state === 'completed') {
                return true;
            }

            return $this->removeLocked($revocation);
        } finally {
            $lock->release();
        }
    }

    private function removeLocked(RoleRevocation $revocation): bool
    {
        try {
            if (!$revocation->credentials) {
                return false;
            }
            $credentials = app(RoleTokens::class)->valid($revocation->credentials, $revocation->provider);
            $revocation->update(['credentials' => $credentials]);
            $response = app(DiscordRoleApi::class)->remove($revocation->application_id, $credentials['access_token']);
            if ($response->status() === 401) {
                $credentials = app(RoleTokens::class)->valid($credentials, $revocation->provider, true);
                $revocation->update(['credentials' => $credentials]);
                $response = app(DiscordRoleApi::class)->remove(
                    $revocation->application_id,
                    $credentials['access_token'],
                );
            }
            if ($response->status() !== 404) {
                app(RoleTokens::class)->ensure($response);
            }
            $revocation->update([
                'state' => 'completed',
                'credentials' => null,
                'completed_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
            ]);

            return true;
        } catch (RoleApiFailure $exception) {
            $revocation->update([
                'state' => $exception->requiresAuthorization ? 'authorization_required' : 'pending',
                'credentials' => $exception->requiresAuthorization ? null : $revocation->credentials,
                'last_error' => $exception->reason,
                'attempts' => $revocation->attempts + 1,
                'next_attempt_at' => $exception->requiresAuthorization
                    ? null
                    : now()->addSeconds($exception->retryAfter),
            ]);
        } catch (DecryptException) {
            $revocation->update([
                'state' => 'authorization_required',
                'credentials' => null,
                'last_error' => 'authorization_expired',
                'next_attempt_at' => null,
            ]);
        } catch (Throwable) {
            $revocation->update([
                'last_error' => 'unavailable',
                'attempts' => $revocation->attempts + 1,
                'next_attempt_at' => now()->addMinutes(5),
            ]);
        }

        return false;
    }

    private function repairRevocations(User $user, string $application, string $subject, array $credentials): void
    {
        $hash = $this->subjectHash($application, $subject);
        $pending = RoleRevocation::query()
            ->where('application_id', $application)
            ->where('state', '!=', 'completed')
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('subject_hash', $hash))
            ->get();
        foreach ($pending as $revocation) {
            /** @var RoleRevocation $revocation */
            if (!hash_equals($revocation->subject_hash, $hash)) {
                throw ValidationException::withMessages([
                    'discord_roles' => __('admin.integrations.discord.original_account_required'),
                ]);
            }
            $lock = Cache::lock('discord-role-connection:'.$revocation->connection_id, 60);
            if (!$lock->get()) {
                throw ValidationException::withMessages([
                    'discord_roles' => __('admin.integrations.discord.removal_pending'),
                ]);
            }
            try {
                $revocation = $revocation->fresh();
                if (!$revocation || $revocation->state === 'completed') {
                    continue;
                }
                $revocation->update([
                    'credentials' => $credentials,
                    'state' => 'pending',
                    'next_attempt_at' => now(),
                    'last_error' => null,
                ]);
                if (!$this->removeLocked($revocation)) {
                    throw ValidationException::withMessages([
                        'discord_roles' => __('admin.integrations.discord.removal_pending'),
                    ]);
                }
            } finally {
                $lock->release();
            }
        }
    }

    private function eligible(RoleConnection $connection, User $user): bool
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        if (
            !$this->active($user) ||
            !$this->ready() ||
            $connection->provider !== $this->provider() ||
            $connection->application_id !== (string) $settings->get('oauth.'.$connection->provider.'.client_id')
        ) {
            return false;
        }
        $expected = app(RoleMetadata::class)->selected();
        $consented = $connection->fields;
        if (!is_array($consented)) {
            return false;
        }
        sort($expected);
        sort($consented);
        if ($expected !== $consented) {
            return false;
        }

        return !$connection->oauth_account_id ||
            $connection
                ->oauthAccount()
                ->where('user_id', $user->id)
                ->identity($connection->provider, $connection->subject)
                ->exists();
    }

    private function active(User $user): bool
    {
        return !$user->anonymized_at && !$user->import_locked && !$user->isBanned();
    }

    private function credentials(RoleConnection $connection): array
    {
        return [
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
            'expires_at' => $connection->expires_at->toIso8601String(),
            'client_credentials' => $connection->client_credentials,
        ];
    }

    private function saveTokens(RoleConnection $connection, array $credentials): void
    {
        $connection->update($credentials);
    }

    private function lockKey(int $userId, string $provider): string
    {
        return 'discord-roles:'.$userId.':'.$provider;
    }

    private function subjectHash(string $application, string $subject): string
    {
        return hash('sha256', $application.':'.$subject);
    }
}
