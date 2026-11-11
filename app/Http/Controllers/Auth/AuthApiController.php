<?php

namespace App\Http\Controllers\Auth;

use App\Events\ApiProfileBuilding;
use App\Exceptions\ConfirmationRequired;
use App\Http\Controllers\Controller;
use App\Kraft\Auth\ApiChallenges;
use App\Kraft\Auth\ApiScopes;
use App\Kraft\Auth\ApiTokens;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Auth\Credentials;
use App\Kraft\Auth\TwoFactorVerifier;
use App\Kraft\Audit;
use App\Kraft\Settings;
use App\Kraft\Site\ViewCapabilities;
use App\Kraft\System\SensitiveAction;
use App\Models\Accounts\ApiAccessToken;
use App\Models\Accounts\GameIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use JsonException;
use Random\RandomException;
use Illuminate\Contracts\Cache\LockTimeoutException;

class AuthApiController extends Controller
{
    /**
     * @throws JsonException
     * @throws RandomException
     */
    public function authenticate(
        Request $request,
        Credentials $credentials,
        ApiTokens $tokens,
        ApiChallenges $challenges,
    ): JsonResponse {
        $data = $request->validate(
            [
                'login' => 'required|string|max:255',
                'password' => 'required|string|max:1024',
                'code' => 'nullable|string|max:32',
                'name' => 'nullable|string|max:120',
                'scopes' => 'nullable|array|min:1|max:100',
                'scopes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(app(ApiScopes::class)->all()))],
                'lifetime' => 'nullable|integer|between:15,43200',
            ],
            [],
            ['login' => __('site.auth.login.identifier')],
        );
        $user = $credentials->verify($data['login'], $data['password']);
        $verifiedStamp = $tokens->stamp($user);
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $options = [
            'name' => $data['name'] ?? __('admin.integrations.auth_api.default_device'),
            'scopes' => $data['scopes'] ?? ['profile:read', 'games:read'],
            'minutes' => $data['lifetime'] ?? (int) $settings->get('auth.api.lifetime', 1440),
        ];
        if ($user->two_factor_confirmed_at && empty($data['code'])) {
            return response()->json(
                [
                    'success' => true,
                    'data' => [
                        'status' => 'pending',
                        'reason' => '2fa',
                        'challenge' => $challenges->create($user, $options),
                        'expires_in' => 300,
                    ],
                ],
                202,
            );
        }
        if ($user->two_factor_confirmed_at) {
            $user = app(TwoFactorVerifier::class)->verify($user->id, $data['code']);
        }

        return $this->issued(
            $tokens->issue($user, $options['name'], $options['scopes'], $options['minutes'], $verifiedStamp),
        );
    }

    /**
     * @throws LockTimeoutException
     */
    public function challenge(Request $request, ApiChallenges $challenges): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'code' => 'required|string|max:32',
        ]);

        return $this->issued($challenges->complete($data['challenge'], $data['code']));
    }

    public function verify(Request $request): JsonResponse
    {
        $token = $request->attributes->get('auth.api_token');

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'success',
                'user' => $this->profile($token),
                'scopes' => $token->scopes,
                'expires_at' => $token->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function games(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['games' => $this->identities($request->user()->id)]]);
    }

    public function token(Request $request): JsonResponse
    {
        $token = $request->attributes->get('auth.api_token');
        $principal = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'success',
                'principal' => [
                    'id' => $principal->id,
                    'type' => $principal->account_type,
                    'username' => $principal->username,
                ],
                'scopes' => $token->scopes,
                'expires_at' => $token->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function rotate(Request $request, ApiTokens $tokens): JsonResponse
    {
        return $this->issued($tokens->rotate($request->attributes->get('auth.api_token')));
    }

    public function logout(Request $request): Response
    {
        $token = $request->attributes->get('auth.api_token');
        $token->forceFill(['revoked_at' => now()])->save();
        Audit::record('auth.api_token_revoked', ['id' => $token->id], $request->user());

        return response()->noContent();
    }

    public function settings(Settings $settings): View
    {
        return view('admin.settings.api.index', [
            'settings' => $settings->all(),
            'scopes' => app(ApiScopes::class)->all(),
        ]);
    }

    public function configure(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'lifetime' => 'required|integer|between:15,43200',
        ]);
        $settings->set('auth.api.enabled', $request->boolean('enabled'));
        $settings->set('auth.api.lifetime', (int) $data['lifetime']);
        Audit::record('auth.api_configured');

        return back()->with('status', __('admin.integrations.auth_api.saved'));
    }

    public function account(Request $request): View|Response
    {
        $user = $request->user();
        $created = $request->session()->pull('auth.api.created');
        if (
            !$request->expectsJson() &&
            is_array($created) &&
            ($created['user_id'] ?? null) === $user->id &&
            is_string($created['raw'] ?? null) &&
            preg_match('/^([a-f0-9-]{36})\|([a-f0-9]{64})$/i', $created['raw'], $parts) &&
            $parts[1] === ($created['token_id'] ?? null)
        ) {
            $token = $user->apiAccessTokens()->active()->find($created['token_id']);
            if (
                $token &&
                hash_equals($token->token_hash, hash('sha256', $parts[2])) &&
                hash_equals($token->security_stamp, app(ApiTokens::class)->stamp($user))
            ) {
                return response()
                    ->view('default.user.account.tokens.created', ['token' => $token, 'raw' => $created['raw']])
                    ->withHeaders(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
            }
        }
        /** @var Settings $settings */
        $settings = app(Settings::class);

        return view('default.user.account.tokens.index', [
            'scopes' => app(ApiScopes::class)->availableTo($user),
            'canCreate' => $settings->get('auth.api.enabled', false) &&
                app(AuthPolicy::class)->allowsLocal() &&
                $user->local_login_enabled &&
                !$user->import_locked &&
                !$user->anonymized_at &&
                !$user->force_password_reset &&
                !$user->isBanned(),
            'lifetime' => (int) $settings->get('auth.api.lifetime', 1440),
            'tokens' => $user->apiAccessTokens()->active()->latest('created_at')->paginate(20),
        ]);
    }

    /**
     * @throws JsonException
     */
    public function store(Request $request, ApiTokens $tokens, SensitiveAction $sensitive): Response|RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'scopes' => 'required|array|min:1|max:100',
            'scopes.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(array_keys(app(ApiScopes::class)->availableTo($request->user()))),
            ],
            'lifetime' => 'required|integer|between:15,43200',
        ]);
        $stamp = $tokens->stamp($request->user());
        $sensitive->confirm($request);
        [$token, $raw] = $tokens->issue($request->user(), $data['name'], $data['scopes'], $data['lifetime'], $stamp);

        if (ConfirmationRequired::applies($request) && $request->header('X-Kraft-Form') === 'true') {
            return redirect()
                ->route('account.tokens.index')
                ->with('auth.api.created', ['user_id' => $request->user()->id, 'token_id' => $token->id, 'raw' => $raw])
                ->withHeaders(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
        }

        return response()
            ->view('default.user.account.tokens.created', ['token' => $token, 'raw' => $raw], 201)
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
    }

    public function revoke(Request $request): RedirectResponse
    {
        $request->validate(['token' => 'nullable|uuid']);

        $query = $request->user()->apiAccessTokens()->whereNull('revoked_at');
        if ($request->filled('token')) {
            $query->whereKey($request->input('token'));
        }
        $query->update(['revoked_at' => now()]);
        Audit::record('auth.api_tokens_revoked', ['id' => $request->input('token')]);

        $redirect = ViewCapabilities::forRequest($request, withExtensions: false)->canManageTokens
            ? back()
            : redirect()->route('account.index');

        return $redirect->with('status', __('admin.integrations.auth_api.revoked'));
    }

    private function issued(array $issued): JsonResponse
    {
        [$token, $raw] = $issued;
        $token->load('user');

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'success',
                'token_type' => 'Bearer',
                'access_token' => $raw,
                'expires_at' => $token->expires_at->toIso8601String(),
                'scopes' => $token->scopes,
                'user' => $this->profile($token),
            ],
        ]);
    }

    private function profile(ApiAccessToken $token): array
    {
        $user = $token->user;
        $profile = ['id' => $user->id];
        if (in_array('profile:read', $token->scopes, true)) {
            $profile += [
                'username' => $user->username,
                'email_verified' => $user->hasVerifiedEmail(),
                'created_at' => $user->created_at?->toIso8601String(),
                'roles' => $user
                    ->effectiveRoles()
                    ->map(fn ($role) => ['id' => $role->id, 'name' => $role->name, 'slug' => $role->slug])
                    ->values()
                    ->all(),
            ];
        }
        if (in_array('email:read', $token->scopes, true)) {
            $profile['email'] = $user->email;
        }
        if (in_array('games:read', $token->scopes, true)) {
            $profile['games'] = $this->identities($user->id);
        }
        $event = new ApiProfileBuilding($user, $token->scopes);
        event($event);
        if ($event->extensions !== []) {
            $profile['extensions'] = $event->extensions;
        }

        return $profile;
    }

    private function identities(int $userId): array
    {
        return GameIdentity::query()
            ->where('user_id', $userId)
            ->get(['game', 'uuid', 'username', 'verified_at'])
            ->map(
                fn (GameIdentity $identity) => [
                    'game' => $identity->game,
                    'uuid' => $identity->uuid,
                    'username' => $identity->username,
                    'verified_at' => $identity->verified_at?->toIso8601String(),
                ],
            )
            ->all();
    }
}
