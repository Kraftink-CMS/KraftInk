<?php

namespace App\Http\Controllers\Auth;

use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Auth\AuthForms;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Auth\LoginManager;
use App\Models\Accounts\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Response;

class RegistrationCompletionController
{
    public function index(Request $request, AccountPolicy $policy, LoginManager $login): Response|RedirectResponse
    {
        $user = $this->pending($request);
        if (!$user) {
            return redirect()->route('login');
        }
        if (!$policy->requiresRegistrationEmail()) {
            $proof = $request->session()->get('auth.registration_pending');

            return $login->complete($request, $user, $proof['remember'], false, $proof['method']);
        }

        return AuthForms::render(
            $request,
            'default.user.auth.complete_registration',
            'default.user.auth.partials.complete-registration',
            __('auth_flow.game.registration_title'),
        );
    }

    public function store(Request $request, AccountPolicy $policy, LoginManager $login): RedirectResponse
    {
        $user = $this->pending($request);
        if (!$user) {
            return redirect()->route('login');
        }
        if (is_string($email = $request->input('email'))) {
            $email = mb_strtolower(trim($email));
            $request->merge(['email' => $email === '' ? null : $email]);
        }
        $proof = $request->session()->get('auth.registration_pending');
        $registered = app(InGameSignInPolicy::class)->withStableConfiguration(function () use ($request, $policy, $user, $proof): User {
            $data = $request->validate([
                'email' => [
                    $policy->requiresRegistrationEmail() ? 'required' : 'nullable',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($user->id),
                ],
                'terms' => 'accepted',
            ]);

            return DB::transaction(function () use ($user, $data, $proof): User {
                $current = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_unless($this->matches($current, $proof), 403);
                $current->forceFill([
                    'email' => $data['email'] ?? null,
                    'email_verified_at' => null,
                    'registration_pending' => false,
                ])->save();

                return $current;
            }, 3);
        });
        DB::afterCommit(fn () => event(new Registered($registered)));
        $request->session()->forget('auth.registration_pending');

        return $login->complete($request, $registered, $proof['remember'], false, $proof['method']);
    }

    private function pending(Request $request): ?User
    {
        $proof = $request->session()->get('auth.registration_pending');
        if (!$proof || $proof['at'] <= time() - LoginManager::IDENTITY_PENDING_SECONDS) {
            $request->session()->forget('auth.registration_pending');

            return null;
        }
        $user = User::query()->find($proof['id']);

        return $user && $this->matches($user, $proof) ? $user : null;
    }

    private function matches(User $user, array $proof): bool
    {
        return $user->registration_pending && app(AuthPolicy::class)->allowsLocal() &&
            (int) $user->auth_session_version === $proof['version'] &&
            $user->canAuthenticateAsMember();
    }
}
