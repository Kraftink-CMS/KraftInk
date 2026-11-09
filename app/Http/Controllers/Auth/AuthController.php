<?php

namespace App\Http\Controllers\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Auth\AuthForms;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Auth\Avatars;
use App\Kraft\Auth\Credentials;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Auth\LoginManager;
use App\Kraft\Auth\TwoFactorVerifier;
use App\Kraft\LocalePolicy;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use App\Models\Accounts\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;
use App\Kraft\OAuth\OAuthRegistry;
use PragmaRX\Google2FA\Exceptions\IncompatibleWithGoogleAuthenticatorException;
use PragmaRX\Google2FA\Exceptions\InvalidCharactersException;
use PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException;

class AuthController extends Controller
{
    public function loginForm(Request $request): Response
    {
        return $this->authForm($request, 'login', $this->externalSignInOptions());
    }

    private function externalSignInOptions(): array
    {
        $oauth = app(OAuthRegistry::class);
        $externalLogins = [];
        if (app(AuthPolicy::class)->allowsExternal()) {
            foreach ($oauth->enabled() as $provider) {
                $metadata = $oauth->metadata($provider);
                $name = $metadata['name'] ?? $provider;
                $externalLogins[] = [
                    'url' => route('auth.oauth.redirect', ['provider' => $provider]),
                    'name' => $name,
                    'label' => $metadata['sign_in_name'] ?? $name,
                    'icon' => $metadata['sign_in_icon'] ?? $metadata['icon'] ?? 'box-arrow-in-right',
                ];
            }
        }

        return [
            'externalLogins' => $externalLogins,
            'externalLoginLabels' => array_count_values(array_column($externalLogins, 'label')),
        ];
    }

    public function login(Request $request): RedirectResponse
    {
        if (!app(AuthPolicy::class)->allowsLocal()) {
            throw new HttpException(403);
        }
        $data = $request->validate(
            [
                'login' => 'required|string|max:255',
                'password' => 'required|string',
            ],
            [],
            ['login' => __('site.auth.login.identifier')],
        );
        $user = app(Credentials::class)->verify($data['login'], $data['password']);

        return app(LoginManager::class)->complete($request, $user, $request->boolean('remember'));
    }

    public function registerForm(Request $request, Settings $settings): Response
    {
        abort_unless($settings->get('registration.enabled', true) && app(AuthPolicy::class)->allowsLocal(), 403);

        return $this->authForm($request, 'register', $this->externalSignInOptions());
    }

    private function authForm(Request $request, string $form, array $data = []): Response
    {
        [$page, $title] = match ($form) {
            'forgot' => ['password.forgot', 'site.auth.reset.title'],
            'reset' => ['password.reset', 'site.auth.password.choose'],
            'two-factor' => ['two_factor', 'site.auth.two_factor.title'],
            default => [$form, 'site.auth.'.$form.'.heading'],
        };

        return AuthForms::render(
            $request,
            'default.user.auth.'.$page,
            'default.user.auth.partials.'.$form,
            __($title),
            $data,
        );
    }

    public function register(Request $request, Settings $settings): RedirectResponse
    {
        $user = app(InGameSignInPolicy::class)->withStableConfiguration(function () use ($request, $settings): User {
            abort_unless($settings->get('registration.enabled', true) && app(AuthPolicy::class)->allowsLocal(), 403);
            AccountIdentity::normalize($request);
            $data = $request->validate([
                'username' => AccountIdentity::usernameRules(),
                'email' => [
                    app(AccountPolicy::class)->requiresRegistrationEmail() ? 'required' : 'nullable',
                    'email',
                    'max:255',
                    'unique:users,email',
                ],
                'password' => ['required', 'confirmed', app(AuthPolicy::class)->password()],
                'terms' => 'accepted',
            ]);
            $data['email'] = isset($data['email']) ? strtolower($data['email']) : null;
            $data['locale'] = app()->getLocale();

            return User::query()->create($data);
        });
        event(new Registered($user));

        return app(LoginManager::class)->complete($request, $user);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function account(Request $request): View
    {
        $secret = $request->session()->get('twofactor.setup');
        $qr = null;
        if ($secret) {
            $user = $request->user();
            $uri = new Google2FA()->getQRCodeUrl(config('app.name'), $user->email ?? $user->username, $secret);
            $qr = new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd()))->writeString($uri);
        }

        return view('default.user.account.index', [
            'user' => $request->user(),
            'canSetPassword' => app(AccountPolicy::class)->allowsSettingPassword($request->user()),
            'avatarControls' => app(Avatars::class)->controls($request->user()),
            'secret' => $secret,
            'qr' => $qr,
            'recoveryCodes' => $request->session()->get('twofactor.codes'),
        ]);
    }

    public function profile(Request $request): RedirectResponse
    {
        $user = $request->user();
        AccountIdentity::normalize($request, $user);
        $localePolicy = app(LocalePolicy::class);
        $rules = [
            'username' => AccountIdentity::usernameRules($user),
            'email' => [
                $user->email || app(AccountPolicy::class)->requiresVerifiedEmail() ? 'required' : 'nullable',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ];
        if ($localePolicy->allowsChoice()) {
            $rules['locale'] = ['required', Rule::in(array_keys(config('kraft.locales')))];
        }
        $data = $request->validate($rules);
        if (!app(AccountPolicy::class)->allows('username')) {
            if ($data['username'] !== $user->username) {
                throw new HttpException(403);
            }
        }
        $data['email'] = isset($data['email']) ? strtolower($data['email']) : null;
        if ($user->email !== $data['email']) {
            app(SensitiveAction::class)->confirm($request);
            $user->forceFill(['email_verified_at' => null]);
        }
        $user->fill($data)->save();
        $response = back()->with('status', __('site.auth.messages.profile_saved'));

        return isset($data['locale'])
            ? $response->withCookie($localePolicy->choose($request, $data['locale']))
            : $response;
    }

    public function changePassword(Request $request, AccountPolicy $policy): RedirectResponse
    {
        app(InGameSignInPolicy::class)->withStableConfiguration(function () use ($request, $policy): void {
            $user = $request->user()->refresh();
            abort_unless($policy->allowsSettingPassword($user), 403);
            $data = $request->validate([
                'password' => ['required', 'confirmed', app(AuthPolicy::class)->password()],
            ]);
            app(SensitiveAction::class)->confirm($request, 'current_password');
            $user->forceFill([
                'password' => $data['password'],
                'local_login_enabled' => true,
                'force_password_reset' => false,
                'oauth_recovery_required_at' => null,
                'remember_token' => Str::random(60),
            ])->save();
        });
        Audit::record('auth.password_changed');

        return back()->with('status', __('site.auth.messages.password_changed'));
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws InvalidCharactersException
     * @throws SecretKeyTooShortException
     */
    public function setupTwoFactor(Request $request): RedirectResponse
    {
        app(AuthPolicy::class)->confirmCredentials($request);
        if ($request->user()->two_factor_confirmed_at) {
            throw new HttpException(409);
        }
        $request->session()->put('twofactor.setup', new Google2FA()->generateSecretKey(32));

        return back();
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws InvalidCharactersException
     * @throws SecretKeyTooShortException
     */
    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|digits:6']);

        $secret = $request->session()->get('twofactor.setup');
        $step = $secret ? new Google2FA()->verifyKeyNewer($secret, $request->string('code')->toString(), 0, 1) : false;
        if ($step === false) {
            throw ValidationException::withMessages(['code' => __('site.auth.messages.invalid_code')]);
        }
        $codes = collect(range(1, 10))->map(fn () => Str::random(12))->all();
        $request
            ->user()
            ->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => array_map(fn ($code) => hash('sha256', $code), $codes),
                'two_factor_last_step' => $step,
            ])
            ->save();
        $request->session()->forget('twofactor.setup');
        $request->session()->flash('twofactor.codes', $codes);
        Audit::record('auth.two_factor_enabled');

        return back();
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        if (!$request->user()->two_factor_confirmed_at) {
            throw new HttpException(403);
        }
        app(SensitiveAction::class)->confirm($request);
        $request
            ->user()
            ->forceFill([
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_step' => null,
            ])
            ->save();
        Audit::record('auth.two_factor_disabled');

        return back();
    }

    public function challengeForm(Request $request): Response
    {
        if (!$request->session()->has('auth.pending')) {
            throw new HttpException(403);
        }

        return $this->authForm($request, 'two-factor');
    }

    public function challenge(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string|max:32']);

        $pending = $request->session()->get('auth.pending');
        if (!isset($pending['at'], $pending['method']) || $pending['at'] < time() - 300) {
            throw new HttpException(403);
        }
        $user = $this->verifyTwoFactor($pending['id'], $request->string('code')->toString());
        if ($user->import_locked || $user->isBanned()) {
            throw new HttpException(403);
        }
        $request->session()->forget('auth.pending');

        return app(LoginManager::class)->complete(
            $request,
            $user,
            $pending['remember'],
            false,
            $pending['method'],
        );
    }

    public function forgotForm(Request $request): Response
    {
        return $this->authForm($request, 'forgot');
    }

    public function forgot(Request $request, AccountPolicy $policy): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink([
            'email' => strtolower($request->input('email')),
            'account_type' => 'human',
            function (Builder $query) use ($policy): void {
                $user = (clone $query)->first();
                if (!$user || !$policy->allowsPasswordReset($user)) {
                    $query->whereRaw('1 = 0');
                }
            },
        ]);

        return back()->with('status', __('site.auth.messages.reset_sent'));
    }

    public function resetForm(Request $request, string $token): Response
    {
        return $this->authForm($request, 'reset', [
            'token' => $token,
            'email' => $request->input('email'),
        ]);
    }

    public function reset(Request $request, AccountPolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', app(AuthPolicy::class)->password()],
        ]);
        $status = Password::reset($data + ['account_type' => 'human'], function (User $user, string $password) use ($policy): void {
            app(InGameSignInPolicy::class)->withStableConfiguration(function () use ($user, $password, $policy): void {
                $user->refresh();
                abort_if($user->isServiceAccount() || !$policy->allowsPasswordReset($user), 403);
                $user->forceFill([
                    'password' => $password,
                    'local_login_enabled' => true,
                    'remember_token' => Str::random(60),
                    'force_password_reset' => false,
                    'oauth_recovery_required_at' => null,
                ])->save();
            });
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect('/login')->with('status', __('site.auth.messages.password_changed'));
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect('/account');
    }

    public function sendVerification(Request $request): RedirectResponse
    {
        if (!$request->user()->email) {
            return redirect()->route('account.index')->with('warning', __('auth_flow.email.add_first'));
        }
        if (!$request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', __('site.auth.messages.verification_sent'));
    }

    private function verifyTwoFactor(int $id, string $code): User
    {
        return app(TwoFactorVerifier::class)->verify($id, $code);
    }
}
