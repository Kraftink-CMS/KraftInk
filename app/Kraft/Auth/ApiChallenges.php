<?php

namespace App\Kraft\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Random\RandomException;
use JsonException;
use Illuminate\Contracts\Cache\LockTimeoutException;

class ApiChallenges
{
    /**
     * @throws RandomException
     * @throws JsonException
     */
    public function create(User $user, array $options): string
    {
        if ($user->isServiceAccount()) {
            throw new HttpException(403);
        }
        $challenge = bin2hex(random_bytes(32));
        Cache::put(
            $this->key($challenge),
            [
                'user' => $user->id,
                'stamp' => app(ApiTokens::class)->stamp($user),
                'options' => $options,
            ],
            300,
        );

        return $challenge;
    }

    /**
     * @throws LockTimeoutException
     */
    public function complete(string $challenge, string $code): array
    {
        $key = $this->key($challenge);

        return Cache::lock($key.'.lock', 10)->block(3, function () use ($key, $code) {
            $data = Cache::get($key);
            $attempts = $key.'.attempts';
            if (!is_array($data) || RateLimiter::tooManyAttempts($attempts, 5)) {
                throw new HttpException(401, __('admin.integrations.auth_api.challenge_expired'));
            }
            RateLimiter::hit($attempts, 300);
            $user = User::query()->find($data['user']);
            if (
                !$user ||
                $user->isServiceAccount() ||
                !hash_equals($data['stamp'], app(ApiTokens::class)->stamp($user))
            ) {
                throw new HttpException(401, __('admin.integrations.auth_api.challenge_expired'));
            }
            app(ApiTokens::class)->assertEligible($user, false);
            $user = app(TwoFactorVerifier::class)->verify($user->id, $code);
            Cache::forget($key);
            RateLimiter::clear($attempts);

            return app(ApiTokens::class)->issue(
                $user,
                $data['options']['name'],
                $data['options']['scopes'],
                $data['options']['minutes'],
                $data['stamp'],
            );
        });
    }

    private function key(string $challenge): string
    {
        return 'auth.api.challenge.'.hash('sha256', $challenge);
    }
}
