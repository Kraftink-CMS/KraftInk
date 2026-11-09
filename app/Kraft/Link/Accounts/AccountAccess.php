<?php

namespace App\Kraft\Link\Accounts;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Events\AuthenticatingUser;
use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Auth\Credentials;
use App\Kraft\Auth\TwoFactorVerifier;
use App\Kraft\Games\GameRegistry;
use App\Models\Accounts\GameIdentity;
use App\Models\Servers\Server;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class AccountAccess
{
    public function target(Server $server, string $uuid): array
    {
        /** @var GameRegistry $registry */
        $registry = app(GameRegistry::class);
        $uuid = $registry->get($server->game)->normalizeUuid($uuid);
        $identity = GameIdentity::query()->where('game', $server->game)->where('uuid', $uuid)->first();
        if ($identity === null) {
            abort(404);
        }
        /** @var User|null $user */
        $user = $identity->user()->lockForUpdate()->first();
        if ($user === null) {
            abort(404);
        }
        $this->ordinary($user);
        app(GameRegistry::class)->assertUserCanAuthenticate($user);

        return [$user, $identity];
    }

    public function ordinary(User $user): void
    {
        if (
            $user->isServiceAccount() ||
            $user->isBanned() ||
            $user->anonymized_at ||
            $user->registration_pending ||
            $user->import_locked ||
            $user->force_password_reset ||
            $user->hasPermission('admin.access') ||
            $user
                ->effectiveRoles()
                ->contains(fn ($role) => $role->slug === 'owner' || in_array('*', $role->permissions, true))
        ) {
            abort(404);
        }
    }

    public function proof(User $user, array $data): User
    {
        if (!app(AuthPolicy::class)->allowsLocal() || !$user->local_login_enabled) {
            throw new HttpException(403);
        }
        $attempts = 'link.account-proof.user.'.$user->id;
        if (RateLimiter::tooManyAttempts($attempts, 5)) {
            $seconds = RateLimiter::availableIn($attempts);
            throw new HttpException(
                429,
                __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
                null,
                [
                    'Retry-After' => (string) $seconds,
                ],
            );
        }
        RateLimiter::hit($attempts);
        $validator = Validator::make($data, [
            'current_password' => 'required|string|max:1024',
            'code' => $user->two_factor_confirmed_at ? 'required|string|max:32' : 'nullable|string|max:32',
        ]);
        $validator->validate();
        $verified = app(Credentials::class)->verify($user->username, $data['current_password']);
        if ($verified->id !== $user->id) {
            throw new HttpException(403);
        }
        if ($user->two_factor_confirmed_at) {
            $verified = app(TwoFactorVerifier::class)->verify($user->id, $data['code']);
        }
        event(new AuthenticatingUser($verified, 'link-account'));

        return $verified;
    }

    public function verifiedEmail(User $user): void
    {
        if (app(AccountPolicy::class)->requiresVerifiedEmail() && !$user->hasVerifiedEmail()) {
            throw new HttpException(403, __('admin.integrations.auth_api.email_required'));
        }
    }
}
