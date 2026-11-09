<?php

namespace App\Kraft\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorVerifier
{
    public function verify(int $userId, string $code): User
    {
        return DB::transaction(function () use ($userId, $code) {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            if (!$user->two_factor_confirmed_at) {
                throw new HttpException(403);
            }
            $codes = $user->two_factor_recovery_codes ?? [];
            $index = array_search(hash('sha256', $code), $codes, true);
            if ($index !== false) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return $user;
            }
            $step = new Google2FA()->verifyKeyNewer(
                $user->two_factor_secret,
                $code,
                $user->two_factor_last_step ?? 0,
                1,
            );
            if ($step === false) {
                throw ValidationException::withMessages(['code' => __('site.auth.messages.used_code')]);
            }
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return $user;
        }, 3);
    }
}
