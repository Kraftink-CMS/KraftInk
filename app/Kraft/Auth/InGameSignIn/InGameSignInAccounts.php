<?php

namespace App\Kraft\Auth\InGameSignIn;

use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InGameSignInAccounts
{
    public function __construct(
        private readonly GameRegistry $games,
        private readonly Settings $settings,
        private readonly InGameSignInPolicy $policy,
    ) {
    }

    public function approval(string $game, array $profile): ?array
    {
        $identity = GameIdentity::query()->where('game', $game)->where('uuid', $profile['uuid'])->first();
        if (!$identity) {
            return $this->canRegister($profile['username']) ? ['user_id' => null, 'version' => null] : null;
        }
        $user = $identity->user;
        if (!$identity->verified_at || !$user || !$this->policy->allowsUser($user)) {
            return null;
        }

        return ['user_id' => $user->id, 'version' => (int) $user->auth_session_version];
    }

    public function requiresEmail(): bool
    {
        return app(AccountPolicy::class)->requiresRegistrationEmail();
    }

    public function finish(string $game, array $profile, array $approval, ?string $email): User
    {
        try {
            return DB::transaction(function () use ($game, $profile, $approval, $email): User {
                if ($approval['user_id'] !== null) {
                    $user = User::query()->lockForUpdate()->find($approval['user_id']);
                    $identity = $user ? $this->games->identityFor($user, $game) : null;
                    if (
                        !$user || !$this->policy->allowsUser($user) || (int) $user->auth_session_version !== $approval['version'] ||
                        !$identity?->verified_at || $identity->uuid !== $profile['uuid']
                    ) {
                        throw $this->unavailable();
                    }

                    return $user;
                }
                if (
                    !$this->canRegister($profile['username']) ||
                    GameIdentity::query()->where('game', $game)->where('uuid', $profile['uuid'])->exists()
                ) {
                    throw $this->unavailable();
                }
                if ($email === null && $this->requiresEmail()) {
                    throw ValidationException::withMessages(['email' => __('validation.required', ['attribute' => 'email'])]);
                }
                $user = new User([
                    'username' => $profile['username'],
                    'email' => $email,
                    'locale' => app()->getLocale(),
                ]);
                $user->forceFill(['local_login_enabled' => false])->save();
                $this->games->verifyIdentity($user, $game, $profile, $profile['source'], replaceUnverified: false);
                DB::afterCommit(fn () => event(new Registered($user)));

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw $this->unavailable();
        }
    }

    private function canRegister(string $name): bool
    {
        return $this->settings->get('registration.enabled', true) &&
            !User::query()->whereRaw(AccountIdentity::expression().' = ?', [strtolower($name)])->exists();
    }

    private function unavailable(): ValidationException
    {
        return ValidationException::withMessages(['in_game_sign_in' => __('in-game-sign-in.unavailable')]);
    }
}
