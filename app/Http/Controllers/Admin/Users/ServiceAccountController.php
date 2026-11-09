<?php

namespace App\Http\Controllers\Admin\Users;

use App\Exceptions\ConfirmationRequired;
use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Auth\ServiceAccounts;
use App\Kraft\Auth\ApiTokens;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use App\Models\Accounts\ApiAccessToken;
use App\Models\Accounts\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceAccountController extends Controller
{
    public function index(Request $request, ServiceAccounts $accounts): View
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'state' => ['nullable', Rule::in(['active', 'disabled'])],
        ]);
        $query = $accounts
            ->query($request->user())
            ->select('users.*')
            ->withCount(['apiAccessTokens as active_tokens' => fn ($tokens) => $tokens->active()])
            ->withMax('apiAccessTokens as token_last_used_at', 'last_used_at');
        if (!empty($data['q'])) {
            TableQuery::whereContains($query, ['username'], $data['q']);
        }
        if (($data['state'] ?? null) === 'active') {
            $query->whereNull('service_disabled_at');
        } elseif (($data['state'] ?? null) === 'disabled') {
            $query->whereNotNull('service_disabled_at');
        }
        $services = TableQuery::paginate($query->latest('id'), ['username', 'active_tokens', 'token_last_used_at']);

        return view('admin.service_accounts.index', [
            'services' => $services,
            'accounts' => $accounts,
            'owners' => User::query()
                ->whereIn('id', $services->pluck('service_created_by')->filter())
                ->pluck('username', 'id'),
        ]);
    }

    public function create(Request $request, ServiceAccounts $accounts): View
    {
        $accounts->query($request->user());

        return $this->form($request, $accounts, null);
    }

    public function edit(Request $request, ServiceAccounts $accounts, User $account): View|Response
    {
        $accounts->assertCanManage($request->user(), $account);
        $created = $request->session()->pull('service_accounts.created_token');
        if (
            !$request->expectsJson() &&
            is_array($created) &&
            ($created['actor_id'] ?? null) === $request->user()->id &&
            ($created['account_id'] ?? null) === $account->id &&
            is_string($created['actor_stamp'] ?? null) &&
            hash_equals($created['actor_stamp'], app(ApiTokens::class)->stamp($request->user())) &&
            is_string($created['raw'] ?? null) &&
            preg_match('/^([a-f0-9-]{36})\|([a-f0-9]{64})$/i', $created['raw'], $parts) &&
            $parts[1] === ($created['token_id'] ?? null)
        ) {
            $token = $account->apiAccessTokens()->active()->find($created['token_id']);
            if (
                $token &&
                !$account->service_disabled_at &&
                hash_equals($token->token_hash, hash('sha256', $parts[2])) &&
                hash_equals($token->security_stamp, app(ApiTokens::class)->stamp($account))
            ) {
                return response()
                    ->view('admin.service_accounts.secret', ['account' => $account, 'token' => $token, 'raw' => $created['raw']])
                    ->withHeaders(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
            }
        }

        return $this->form($request, $accounts, $account);
    }

    private function form(Request $request, ServiceAccounts $accounts, ?User $account): View
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);

        return view('admin.service_accounts.edit', [
            'account' => $account,
            'definitions' => $accounts->manageableBy($request->user()),
            'tokens' => $account
                ? TableQuery::paginate(
                    $account->apiAccessTokens()->latest('created_at'),
                    ['name', 'expires_at', 'last_used_at'],
                    search: ['name'],
                )
                : null,
            'owner' => $account?->service_created_by ? User::query()->find($account->service_created_by) : null,
            'lifetime' => (int) $settings->get('auth.api.lifetime', 1440),
            'apiEnabled' => (bool) $settings->get('auth.api.enabled', false),
        ]);
    }

    public function store(Request $request, ServiceAccounts $accounts, SensitiveAction $sensitive): RedirectResponse
    {
        $data = $request->validate($this->identityRules() + $this->scopeRules());
        $sensitive->confirm($request);
        $account = $accounts->create($request->user(), $data);

        return redirect()
            ->route('admin.service_accounts.edit', $account)
            ->with('status', __('service_accounts.created'));
    }

    public function update(Request $request, ServiceAccounts $accounts, User $account): RedirectResponse
    {
        $data = $request->validate($this->identityRules());
        $accounts->update($request->user(), $account, $data);

        return back()->with('status', __('service_accounts.saved'));
    }

    public function scopes(
        Request $request,
        ServiceAccounts $accounts,
        SensitiveAction $sensitive,
        User $account,
    ): RedirectResponse {
        $accounts->assertCanManage($request->user(), $account);
        $data = $request->validate($this->scopeRules());
        $accounts->update($request->user(), $account, $data, function (User $actor) use ($request, $sensitive): void {
            auth()->setUser($actor);
            $request->setUserResolver(fn () => $actor);
            $sensitive->confirm($request);
        });

        return back()->with('status', __('service_accounts.saved'));
    }

    public function enabled(
        Request $request,
        ServiceAccounts $accounts,
        SensitiveAction $sensitive,
        User $account,
    ): RedirectResponse {
        $accounts->assertCanManage($request->user(), $account);
        $data = $request->validate(['enabled' => 'required|boolean', 'confirmed' => 'accepted']);
        if ((bool) $data['enabled']) {
            $sensitive->confirm($request);
        }
        $accounts->setEnabled($request->user(), $account, (bool) $data['enabled']);

        return back()->with('status', __('service_accounts.saved'));
    }

    public function issue(
        Request $request,
        ServiceAccounts $accounts,
        SensitiveAction $sensitive,
        User $account,
    ): Response|RedirectResponse {
        $accounts->assertCanManage($request->user(), $account);
        $data = $request->validate(
            $this->scopeRules() + [
                'token_name' => 'required|string|max:120',
                'lifetime' => 'required|integer|between:15,43200',
            ],
        );
        $sensitive->confirm($request);
        [$token, $raw] = $accounts->issue(
            $request->user(),
            $account,
            $data['token_name'],
            $data['scopes'],
            $data['lifetime'],
        );

        return $this->secret($request, $account, $token, $raw);
    }

    public function rotate(
        Request $request,
        ServiceAccounts $accounts,
        SensitiveAction $sensitive,
        User $account,
        ApiAccessToken $token,
    ): Response|RedirectResponse {
        $accounts->assertCanManage($request->user(), $account);
        $sensitive->confirm($request);
        [$replacement, $raw] = $accounts->rotate($request->user(), $account, $token);

        return $this->secret($request, $account, $replacement, $raw);
    }

    public function revoke(
        Request $request,
        ServiceAccounts $accounts,
        User $account,
        ?ApiAccessToken $token = null,
    ): RedirectResponse {
        $accounts->revoke($request->user(), $account, $token?->id);

        return back()->with('status', __('service_accounts.revoked'));
    }

    private function secret(Request $request, User $account, ApiAccessToken $token, string $raw): Response|RedirectResponse
    {
        if (ConfirmationRequired::applies($request) && $request->header('X-Kraft-Form') === 'true') {
            return redirect()
                ->route('admin.service_accounts.edit', $account)
                ->with('service_accounts.created_token', [
                    'actor_id' => $request->user()->id,
                    'actor_stamp' => app(ApiTokens::class)->stamp($request->user()),
                    'account_id' => $account->id,
                    'token_id' => $token->id,
                    'raw' => $raw,
                ])
                ->withHeaders(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
        }

        return response()
            ->view('admin.service_accounts.secret', compact('account', 'token', 'raw'), 201)
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
    }

    private function identityRules(): array
    {
        return ['username' => 'required|string|max:60', 'description' => 'nullable|string|max:2000'];
    }

    private function scopeRules(): array
    {
        return ['scopes' => 'required|array|min:1|max:100', 'scopes.*' => 'required|string|distinct|max:160'];
    }
}
