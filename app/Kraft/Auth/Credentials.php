<?php

namespace App\Kraft\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Credentials
{
    public function verify(string $identifier, string $password): User
    {
        if (!app(AuthPolicy::class)->allowsLocal()) {
            throw new HttpException(403);
        }
        $identifier = mb_strtolower(trim($identifier));
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $identifier = AccountIdentity::normalizeUsername($identifier);
        }
        if ($identifier === '') {
            throw ValidationException::withMessages([
                'login' => __('validation.required', ['attribute' => __('site.auth.login.identifier')]),
            ]);
        }
        $matches = User::members()
            ->when(
                filter_var($identifier, FILTER_VALIDATE_EMAIL),
                fn ($query) => $query->whereRaw('LOWER(email) = ?', [$identifier]),
                fn ($query) => $query->whereRaw(AccountIdentity::expression().' = ?', [$identifier]),
            )
            ->limit(2)
            ->get();
        /** @var User|null $user */
        $user = $matches->count() === 1 ? $matches->first() : null;
        $valid = $user && Hash::check($password, $user->password);
        if (
            !$valid ||
            !$user->local_login_enabled ||
            $user->import_locked ||
            $user->anonymized_at ||
            $user->isBanned()
        ) {
            password_verify($password, '$2y$12$DSnm4qHX8x9j6bPYhTnlVuZym.MHZygF1Uu2rRbJXfsU9vCpH8lLe');
            throw ValidationException::withMessages(['login' => __('site.auth.messages.invalid_credentials')]);
        }
        /** @var User $user */
        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
            $user->save();
        }

        return $user;
    }
}
