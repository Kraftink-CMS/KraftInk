<?php

namespace App\Http\Controllers\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\OAuth\OAuthAccount;
use App\Http\Controllers\Controller;
use App\Events\OAuthAccountUnlinked;
use App\Kraft\Audit;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Auth\AuthForms;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Auth\LoginManager;
use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\Contracts\SynchronizesIdentity;
use App\Kraft\OAuth\OAuthChanges;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Settings;
use App\Kraft\Site\ViewCapabilities;
use App\Kraft\System\SensitiveAction;
use App\Models\Accounts\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse as ExternalRedirect;
use Throwable;

class OAuthController extends Controller
{
    public function redirect(Request $request, OAuthRegistry $registry, string $provider): ExternalRedirect
    {
        if ($request->user()) {
            throw new HttpException(403);
        }
        $pending = $request->session()->get('auth.identity_pending');
        if ($pending && $pending['at'] <= time() - LoginManager::IDENTITY_PENDING_SECONDS) {
            $request->session()->forget('auth.identity_pending');

            return redirect()->route('login');
        }
        if (!($pending || app(AuthPolicy::class)->allowsExternal())) {
            throw new HttpException(403);
        }
        if ($pending && !$this->canVerifyGameWith($registry, $provider)) {
            throw new HttpException(403);
        }
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $request->session()->put('oauth.intent', [
            'provider' => $provider,
            'at' => time(),
            'user_id' => $pending['id'] ?? null,
            'pending' => $pending !== null,
            'revision' => (int) $settings->get('oauth.revision', 0),
        ]);

        return $registry->driver($provider)->redirect();
    }

    public function link(Request $request, OAuthRegistry $registry, string $provider): ExternalRedirect
    {
        if ($request->user()->isServiceAccount()) {
            throw new HttpException(403);
        }
        app(SensitiveAction::class)->confirm($request);
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $request->session()->put('oauth.intent', [
            'provider' => $provider,
            'at' => time(),
            'user_id' => $request->user()->id,
            'revision' => (int) $settings->get('oauth.revision', 0),
        ]);

        return $registry->driver($provider)->redirect();
    }

    public function callback(
        Request $request,
        OAuthRegistry $registry,
        Settings $settings,
        string $provider,
    ): RedirectResponse {
        $intent = $request->session()->pull('oauth.intent');
        if (!($intent && $intent['provider'] === $provider && $intent['at'] > time() - 300)) {
            throw new HttpException(403);
        }
        if ((int) ($intent['revision'] ?? 0) !== (int) $settings->get('oauth.revision', 0)) {
            throw new HttpException(403);
        }
        $pending = $request->session()->get('auth.identity_pending');
        if ($intent['user_id']) {
            if ($intent['pending'] ?? false) {
                if (
                    !$pending ||
                    $pending['id'] !== $intent['user_id'] ||
                    $pending['at'] <= time() - LoginManager::IDENTITY_PENDING_SECONDS ||
                    !$this->canVerifyGameWith($registry, $provider)
                ) {
                    throw new HttpException(403);
                }
            } else {
                if ($request->user()?->id !== $intent['user_id']) {
                    throw new HttpException(403);
                }
            }
        } else {
            if ($request->user()) {
                throw new HttpException(403);
            }
        }
        if ($request->has('error')) {
            $request->session()->forget(['state', 'code_verifier']);
            throw ValidationException::withMessages(['oauth' => __('site.account.connections.failed')]);
        }
        try {
            $identity = $registry->driver($provider)->user();
        } catch (Throwable) {
            throw ValidationException::withMessages(['oauth' => __('site.account.connections.failed')]);
        }
        $rawSubject = $identity->getId();
        if (!(is_string($rawSubject) || is_int($rawSubject))) {
            throw new HttpException(422);
        }
        $subject = (string) $rawSubject;
        if (!($subject !== '' && strlen($subject) <= 190)) {
            throw new HttpException(422);
        }
        $adapter = $registry->adapter($provider);
        $proof = [
            'provider' => $provider,
            'subject' => $subject,
            'email' => mb_strtolower(trim((string) $identity->getEmail())),
            'verified' => $adapter->emailVerified($identity),
            'data' => $adapter instanceof SynchronizesIdentity ? $adapter->identityData($identity) : [],
            'at' => time(),
            'revision' => (int) $settings->get('oauth.revision', 0),
        ];
        $account = OAuthAccount::identity($provider, $subject)->first();
        if ($intent['user_id']) {
            $user = User::query()->findOrFail($intent['user_id']);
            if (!$user->canAuthenticateAsMember()) {
                throw new HttpException(403);
            }
            if (($intent['pending'] ?? false) && ($pending['version'] ?? null) !== (int) $user->auth_session_version) {
                throw new HttpException(403);
            }
            if ($account && $account->user_id !== $user->id) {
                throw ValidationException::withMessages(['oauth' => __('site.account.connections.already_linked')]);
            }
            DB::transaction(function () use ($user, $provider, $subject, $proof, $adapter) {
                $user->oauthAccounts()->updateOrCreate(
                    ['provider' => $provider],
                    [
                        'subject' => $subject,
                        'email' => $proof['email'] ?: null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                if ($adapter instanceof SynchronizesIdentity) {
                    $adapter->synchronize($user, $proof['data']);
                }
            });
            if ($intent['pending'] ?? false) {
                return app(LoginManager::class)->complete(
                    $request,
                    $user,
                    $pending['remember'],
                    false,
                    'game_identity',
                );
            }
            Audit::record('oauth.linked', ['provider' => $provider]);

            return redirect('/account/connections')->with('status', __('site.account.connections.linked'));
        }
        if ($account) {
            $user = User::query()->findOrFail($account->user_id);
            if (!$user->canAuthenticateAsMember()) {
                throw new HttpException(403);
            }
            if ($adapter instanceof SynchronizesIdentity) {
                $adapter->synchronize($user, $proof['data']);
            }
        } else {
            if (!$settings->get('registration.enabled', true)) {
                throw new HttpException(403);
            }
            $email = $proof['email'];
            if ($email && User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['oauth' => __('site.account.connections.must_link')]);
            }
            $proof['username'] = AccountIdentity::availableUsername((string) ($identity->getNickname() ?: 'member'));
            $request->session()->put('oauth.registration', $proof);

            return redirect('/register/external');
        }

        return app(LoginManager::class)->complete($request, $user, false, true, 'oauth:'.$provider);
    }

    public function registration(Request $request, Settings $settings, OAuthRegistry $registry): Response
    {
        $proof = $request->session()->get('oauth.registration');
        $this->ensureRegistrationProof($proof, $settings, $registry);

        $policy = app(AccountPolicy::class);

        return AuthForms::render(
            $request,
            'default.user.auth.external_register',
            'default.user.auth.partials.external-register',
            __('site.auth.security.complete'),
            [
                'proof' => $proof,
                'emailRequired' => $policy->requiresRegistrationEmail(),
                'passwordAllowed' => $policy->allowsExternalPassword(),
                'passwordRequired' => $policy->requiresExternalPassword(),
            ],
        );
    }

    public function register(Request $request, OAuthRegistry $registry, Settings $settings): RedirectResponse
    {
        $proof = $request->session()->get('oauth.registration');
        $user = app(InGameSignInPolicy::class)->withStableConfiguration(function () use ($request, $registry, $settings, $proof): User {
            $this->ensureRegistrationProof($proof, $settings, $registry);
            AccountIdentity::normalize($request);
            $policy = app(AccountPolicy::class);
            $data = $request->validate([
                'username' => AccountIdentity::usernameRules(),
                'email' => [
                    $policy->requiresRegistrationEmail() ? 'required' : 'nullable',
                    'email',
                    'max:255',
                    'unique:users,email',
                ],
                'password' => $policy->allowsExternalPassword() ? [
                    $policy->requiresExternalPassword() ? 'required' : 'nullable',
                    'string',
                    'confirmed',
                    app(AuthPolicy::class)->password(),
                ] : ['prohibited'],
                'terms' => 'accepted',
            ]);
            $password = $policy->allowsExternalPassword() ? ($data['password'] ?? null) : null;

            return DB::transaction(function () use ($data, $proof, $registry, $password) {
                $user = User::query()->create([
                    'username' => $data['username'],
                    'email' => $data['email'] ?? null,
                    'password' => $password !== null ? Hash::make($password) : null,
                    'locale' => app()->getLocale(),
                ]);
                $verified =
                    $user->email !== null &&
                    $proof['verified'] &&
                    $proof['email'] === $user->email;
                $user
                    ->forceFill([
                        'local_login_enabled' => $password !== null,
                        'email_verified_at' => $verified ? now() : null,
                    ])
                    ->save();
                $user->oauthAccounts()->create([
                    'provider' => $proof['provider'],
                    'subject' => $proof['subject'],
                    'email' => $proof['email'] ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $adapter = $registry->adapter($proof['provider']);
                if ($adapter instanceof SynchronizesIdentity) {
                    $adapter->synchronize($user, $proof['data']);
                }

                return $user;
            });
        });
        $request->session()->forget('oauth.registration');
        event(new Registered($user));

        return app(LoginManager::class)->complete($request, $user, false, true, 'oauth:'.$proof['provider']);
    }

    private function ensureRegistrationProof(mixed $proof, Settings $settings, OAuthRegistry $registry): void
    {
        abort_unless(
            is_array($proof) &&
            ($proof['at'] ?? 0) > time() - 600 &&
            (int) ($proof['revision'] ?? 0) === (int) $settings->get('oauth.revision', 0) &&
            $settings->get('registration.enabled', true) &&
            app(AuthPolicy::class)->allowsExternal() &&
            in_array($proof['provider'] ?? null, $registry->enabled(), true),
            403,
        );
    }

    public function connections(Request $request, OAuthRegistry $registry): View
    {
        $accounts = $request->user()->oauthAccounts()->get()->keyBy('provider');
        $enabled = $registry->enabled();
        $connectionOptions = [];
        foreach (array_unique([...$registry->all(), ...$accounts->keys()->all()]) as $provider) {
            $isLinked = $accounts->has($provider);
            $isAvailable = in_array($provider, $enabled, true);
            if (!$isLinked && !$isAvailable) {
                continue;
            }
            $connectionOptions[] = [
                'id' => $provider,
                'name' => $registry->metadata($provider)['name'] ?? $provider,
                'isLinked' => $isLinked,
                'description' => __(
                    $isLinked
                        ? ($isAvailable
                            ? 'site.account.connections.is_linked'
                            : 'admin.integrations.oauth.linked_disabled')
                        : 'admin.integrations.oauth.link_available',
                ),
            ];
        }

        return view('default.user.account.connections.index', [
            'providers' => $registry->all(),
            'registry' => $registry,
            'accounts' => $accounts,
            'connectionOptions' => $connectionOptions,
        ]);
    }

    public function unlink(Request $request, string $provider): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        app(InGameSignInPolicy::class)->withStableConfiguration(function () use ($request, $provider): void {
            $remaining = array_values(array_diff(app(OAuthRegistry::class)->enabled(), [$provider]));
            if (!app(OAuthChanges::class)->usable($request->user(), null, $remaining)) {
                throw ValidationException::withMessages(['oauth' => __('site.auth.security.last_connection')]);
            }
            $request->user()->oauthAccounts()->where('provider', $provider)->delete();
        });
        event(new OAuthAccountUnlinked($request->user(), $provider));
        Audit::record('oauth.unlinked', ['provider' => $provider]);

        $redirect = ViewCapabilities::forRequest($request, withExtensions: false)->canManageConnections
            ? back()
            : redirect()->route('account.index');

        return $redirect->with('status', __('site.account.connections.unlinked'));
    }

    private function canVerifyGameWith(OAuthRegistry $registry, string $provider): bool
    {
        $games = app(GameRegistry::class);
        $adapter = $games->active()[$games->activeId()]['connection_adapter'] ?? null;

        return $adapter === null || ($registry->metadata($provider)['adapter'] ?? null) === $adapter;
    }
}
