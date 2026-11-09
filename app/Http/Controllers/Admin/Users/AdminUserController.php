<?php

namespace App\Http\Controllers\Admin\Users;

use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Kraft\System\TableQuery;
use App\Models\Audit\AuditEntry;
use App\Http\Controllers\Controller;
use App\Events\OAuthAccountUnlinked;
use App\Kraft\Audit;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Auth\RoleHierarchy;
use App\Kraft\Settings;
use App\Kraft\SiteDates;
use App\Kraft\System\PersonalData;
use App\Kraft\System\SensitiveAction;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Database\Eloquent\Collection;
use App\Models\OAuth\OAuthAccount;

class AdminUserController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(['active', 'banned', 'unverified', 'closed'])],
            'role' => 'nullable|integer|exists:roles,id',
        ]);
        $query = User::members()->with('roles')->latest('id');
        if (isset($data['q']) && $data['q'] !== '') {
            $columns = $request->user()->hasPermission('users.view-personal') ? ['username', 'email'] : ['username'];
            TableQuery::whereContains($query, $columns, $data['q']);
        }
        if (!empty($data['role'])) {
            $query->whereHas('roles', fn ($roles) => $roles->where('roles.id', $data['role']));
        }
        match ($data['status'] ?? '') {
            'banned' => $query->where(
                fn ($query) => $query->where('banned_permanently', true)->orWhere('banned_until', '>', now()),
            ),
            'unverified' => $query->whereNull('email_verified_at')->whereNull('anonymized_at'),
            'closed' => $query->whereNotNull('anonymized_at'),
            'active' => $query
                ->whereNull('anonymized_at')
                ->where('import_locked', false)
                ->withoutActiveBan(),
            default => null,
        };

        return view('admin.users.index', [
            'users' => TableQuery::paginate($query, ['username', 'last_login_at']),
            'roles' => Role::query()->orderByDesc('position')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        if (
            !($request->user()->hasPermission('users.create') && $request->user()->hasPermission('users.edit-personal'))
        ) {
            throw new HttpException(403);
        }

        return $this->form($request, null);
    }

    public function edit(Request $request, User $user): View
    {
        return $this->form($request, $user);
    }

    private function form(Request $request, ?User $user): View
    {
        if ($user?->isServiceAccount()) {
            throw new NotFoundHttpException();
        }
        $bans = $user
            ? TableQuery::paginate(
                $user->bans()->with('author')->orderByDesc('user_bans.id'),
                [
                    'reason' => 'user_bans.reason',
                    'expires_at' => 'user_bans.expires_at',
                    'revoked_at' => 'user_bans.revoked_at',
                ],
                pageName: 'bans_page',
                search: ['user_bans.reason'],
            )->fragment('member-bans')
            : collect();
        $activity =
            $user && $request->user()->hasPermission('users.view-personal')
                ? TableQuery::paginate(
                    AuditEntry::query()
                        ->where('user_id', $user->id)
                        ->select(['id', 'action', 'ip', 'created_at'])
                        ->latest('id'),
                    ['action', 'ip', 'created_at'],
                    pageName: 'activity_page',
                    search: ['action', 'ip'],
                )->fragment('member-activity')
                : collect();

        return view('admin.users.edit', [
            'member' => $user,
            'roles' => Role::query()->orderByDesc('position')->get(),
            'hierarchy' => app(RoleHierarchy::class),
            'bans' => $bans,
            'activity' => $activity,
            'connections' => $user && $request->user()->hasPermission('users.security')
                    ? $user->oauthAccounts()->get(['id', 'provider', 'created_at'])
                    : collect(),
        ]);
    }

    public function store(Request $request, ?User $user = null): RedirectResponse
    {
        if ($user?->isServiceAccount()) {
            throw new NotFoundHttpException();
        }
        $actor = $request->user();
        if (!$user) {
            if (!($actor->hasPermission('users.create') && $actor->hasPermission('users.edit-personal'))) {
                throw new HttpException(403);
            }
        } else {
            app(RoleHierarchy::class)->assertUser($actor, $user);
            if ($user->anonymized_at !== null) {
                throw new HttpException(403);
            }
        }
        if ($request->has('email') && !$actor->hasPermission('users.edit-personal')) {
            throw new HttpException(403);
        }
        AccountIdentity::normalize($request);
        $rules = [
            'username' => AccountIdentity::usernameRules($user),
            'locale' => ['sometimes', 'required', Rule::in(array_keys(config('kraft.locales')))],
            'roles' => 'sometimes|array',
            'roles.*' => 'integer|exists:roles,id',
            'banned_until' => 'prohibited',
            'banned_permanently' => 'prohibited',
        ];
        if ($actor->hasPermission('users.edit-personal')) {
            $rules['email'] = [
                !$user || $user->email !== null ? 'required' : 'nullable',
                'email', 'max:255', Rule::unique('users')->ignore($user?->id),
            ];
        }
        if (!$user) {
            $rules['new_password'] = ['required', 'confirmed', app(AuthPolicy::class)->password()];
        }
        $data = $request->validate($rules);
        $saved = DB::transaction(function () use ($user, $data, $actor) {
            Role::query()->whereJsonContains('permissions', '*')->lockForUpdate()->get();
            $record = $user ? User::query()->lockForUpdate()->findOrFail($user->id) : new User();
            $actor = $actor->fresh();
            if ($record->exists) {
                app(RoleHierarchy::class)->assertUser($actor, $record);
            }
            $roleIds = array_values(array_unique(array_map('intval', $data['roles'] ?? [])));
            $selected = Role::query()->whereIn('id', $roleIds)->lockForUpdate()->get();
            foreach ($selected as $role) {
                app(RoleHierarchy::class)->assertRole($actor, $role);
            }
            if (
                $record->exists &&
                $record->hasPermission('*') &&
                !$selected->contains(fn (Role $role) => in_array('*', $role->permissions, true))
            ) {
                app(RoleHierarchy::class)->assertOtherOwner($record);
            }
            if ($record->exists && isset($data['email']) && $record->email !== $data['email']) {
                $record->email_verified_at = null;
                $record->auth_session_version++;
                $record->remember_token = Str::random(60);
            }
            $record->fill(array_intersect_key($data, array_flip(['username', 'email', 'locale'])));
            if (!$record->exists) {
                $record->locale ??= app()->getLocale();
                $record->password = $data['new_password'];
                $record->force_password_reset = true;
            }
            $record->save();
            $record->roles()->sync($roleIds);
            Audit::record($user ? 'user.updated' : 'user.created', ['id' => $record->id]);

            return $record;
        }, 3);

        return redirect()->route('admin.users.edit', $saved)->with('status', __('admin.messages.user_saved'));
    }

    public function action(Request $request, User $user): RedirectResponse
    {
        if ($user->isServiceAccount()) {
            throw new NotFoundHttpException();
        }
        app(SiteDates::class)->normalize($request, ['expires_at']);
        $validator = Validator::make($request->all(), [
            'action' => [
                'required',
                Rule::in([
                    'verify',
                    'reset-password',
                    'reset-two-factor',
                    'revoke-sessions',
                    'unlink',
                    'ban',
                    'unban',
                    'close',
                ]),
            ],
        ]);
        $action = $validator->validate()['action'];
        $permission = match ($action) {
            'ban', 'unban' => 'users.ban',
            'close' => 'users.delete',
            default => 'users.security',
        };
        if (!$request->user()->hasPermission($permission)) {
            throw new HttpException(403);
        }
        app(RoleHierarchy::class)->assertUser($request->user(), $user);
        if ($user->anonymized_at || (in_array($action, ['ban', 'close'], true) && $user->id === $request->user()->id)) {
            throw new HttpException(403);
        }
        app(SensitiveAction::class)->confirm($request);
        if ($action === 'close') {
            $request->validate(['confirmation' => 'accepted']);
        }
        $data = $request->validate([
            'reason' => $action === 'ban' ? 'required|string|min:3|max:2000' : 'nullable|string|max:2000',
            'expires_at' => 'nullable|date|after:now',
            'connection' => 'nullable|integer|min:1',
        ]);
        if ($action === 'reset-password') {
            if (!$user->local_login_enabled) {
                throw new HttpException(422);
            }
            $result = Password::sendResetLink(['email' => $user->email]);
            if ($result !== Password::RESET_LINK_SENT) {
                throw ValidationException::withMessages(['action' => __($result)]);
            }
        }
        DB::transaction(function () use ($request, $user, $action, $data) {
            Role::query()->whereJsonContains('permissions', '*')->lockForUpdate()->get();
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            app(RoleHierarchy::class)->assertUser($request->user()->fresh(), $user);
            if (in_array($action, ['ban', 'close'], true)) {
                app(RoleHierarchy::class)->assertOtherOwner($user);
            }
            match ($action) {
                'verify' => $this->verify($user),
                'reset-password' => $user->forceFill(['force_password_reset' => true])->save(),
                'reset-two-factor' => $this->resetTwoFactor($request, $user),
                'unlink' => $this->unlink($user, (int) ($data['connection'] ?? 0)),
                'ban' => $this->ban($request, $user, $data),
                'unban' => $this->unban($request, $user),
                'close' => app(PersonalData::class)->anonymize($user),
                default => null,
            };
            if ($action !== 'close') {
                $user
                    ->forceFill([
                        'auth_session_version' => $user->auth_session_version + 1,
                        'remember_token' => Str::random(60),
                    ])
                    ->save();
            }
            Audit::record('user.'.$action, ['id' => $user->id]);
        }, 3);

        return back()->with('status', __('admin.members.action_done'));
    }

    private function verify(User $user): void
    {
        if (!$user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
            event(new Verified($user));
        }
    }

    private function resetTwoFactor(Request $request, User $user): void
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        if ($request->user()->id === $user->id && $settings->get('security.admin_two_factor', false)) {
            throw new HttpException(403);
        }
        $user
            ->forceFill([
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_step' => null,
            ])
            ->save();
    }

    private function unlink(User $user, int $connection): void
    {
        /** @var Collection<int, OAuthAccount> $accounts */
        $accounts = $user->oauthAccounts()->lockForUpdate()->get();
        if (!$accounts->contains('id', $connection)) {
            throw new NotFoundHttpException();
        }
        if (!$user->local_login_enabled && $accounts->count() <= 1) {
            throw ValidationException::withMessages(['action' => __('site.auth.security.last_connection')]);
        }
        $user->oauthAccounts()->where('id', $connection)->delete();
        event(new OAuthAccountUnlinked($user, $accounts->firstWhere('id', $connection)->provider));
    }

    private function ban(Request $request, User $user, array $data): void
    {
        $this->unban($request, $user);
        $user->bans()->create([
            'author_id' => $request->user()->id,
            'reason' => $data['reason'],
            'expires_at' => $data['expires_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user
            ->forceFill([
                'banned_permanently' => empty($data['expires_at']),
                'banned_until' => $data['expires_at'] ?? null,
            ])
            ->save();
    }

    private function unban(Request $request, User $user): void
    {
        $user
            ->bans()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'updated_at' => now()]);
        $user->forceFill(['banned_permanently' => false, 'banned_until' => null])->save();
    }
}
