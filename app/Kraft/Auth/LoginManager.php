<?php

namespace App\Kraft\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Events\AuthenticatingUser;
use App\Kraft\Audit;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Games\GameRegistry;
use App\Models\Accounts\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginManager
{
    public const int IDENTITY_PENDING_SECONDS = 300;

    public function complete(
        Request $request,
        User $user,
        bool $remember = false,
        bool $challenge = true,
        string $method = 'password',
    ): RedirectResponse {
        if (!$user->canAuthenticateAsMember()) {
            throw new HttpException(403);
        }
        event(new AuthenticatingUser($user, $method));
        if ($challenge && $user->two_factor_confirmed_at) {
            $request->session()->regenerate();
            $request->session()->put('auth.pending', [
                'id' => $user->id,
                'at' => time(),
                'remember' => $remember,
                'method' => $method,
            ]);

            return redirect('/two-factor-challenge');
        }
        try {
            app(GameRegistry::class)->assertUserCanAuthenticate($user);
        } catch (ValidationException $exception) {
            $request->session()->regenerate();
            $request->session()->put('auth.identity_pending', [
                'id' => $user->id,
                'at' => time(),
                'remember' => $remember,
                'version' => (int) $user->auth_session_version,
            ]);

            return redirect('/verify-game')->with('warning', array_merge(...array_values($exception->errors())));
        }
        if ($user->registration_pending) {
            $version = (int) $user->auth_session_version;
            $completed = app(InGameSignInPolicy::class)->withStableConfiguration(function () use ($user, $version): bool {
                return !app(AccountPolicy::class)->requiresRegistrationEmail() &&
                    User::query()->whereKey($user->id)->where('auth_session_version', $version)
                        ->where('registration_pending', true)->update(['registration_pending' => false]) === 1;
            });
            $user->refresh();
            abort_unless((int) $user->auth_session_version === $version, 403);
            if ($completed) {
                event(new Registered($user));
            }
        }
        if ($user->registration_pending) {
            $request->session()->regenerate();
            $request->session()->forget(['auth.pending', 'auth.identity_pending']);
            $request->session()->put('auth.registration_pending', [
                'id' => $user->id,
                'version' => (int) $user->auth_session_version,
                'at' => time(),
                'remember' => $remember,
                'method' => $method,
            ]);

            return redirect()->route('auth.registration.complete');
        }
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('auth.confirmed_at', time());
        $request->session()->put('auth.version', (int) $user->auth_session_version);
        $request->session()->forget(['auth.pending', 'auth.identity_pending', 'auth.registration_pending']);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        Audit::record('auth.login', ['method' => $method]);

        if (app(AccountPolicy::class)->requiresVerifiedEmail() && !$user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended('/account');
    }
}
