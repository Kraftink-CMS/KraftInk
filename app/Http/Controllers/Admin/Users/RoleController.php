<?php

namespace App\Http\Controllers\Admin\Users;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Models\Content\ContentEntry;
use App\Models\Setting;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Auth\DefaultRole;
use App\Kraft\Auth\PermissionCatalog;
use App\Kraft\Auth\RoleHierarchy;
use App\Kraft\ContentIcons;
use App\Kraft\Settings;
use App\Models\Accounts\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Kraft\TextTranslations;

class RoleController extends Controller
{
    public function index(Request $request): View
    {
        $editing = $request->filled('edit') ? Role::query()->findOrFail($request->integer('edit')) : null;
        if ($editing) {
            app(RoleHierarchy::class)->assertRole($request->user(), $editing);
        }
        if ($editing || $request->boolean('create')) {
            return $this->edit($request, $editing);
        }

        return view('admin.roles.index', [
            'roles' => TableQuery::paginate(
                Role::query()->withCount('users')->orderByDesc('position')->orderBy('id'),
                ['name', 'position', 'users' => 'users_count'],
                search: ['name', 'slug'],
            ),
            'hierarchy' => app(RoleHierarchy::class),
        ]);
    }

    public function edit(Request $request, ?Role $role = null): View
    {
        if ($role) {
            app(RoleHierarchy::class)->assertRole($request->user(), $role);
            if ($role->system_key === 'member' && !$request->user()->hasPermission('*')) {
                throw new HttpException(403);
            }
        }

        return view('admin.roles.edit', [
            'editing' => $role,
            'permissionGroups' => app(PermissionCatalog::class)->groups(),
        ]);
    }

    public function permissionDefaults(Request $request): View
    {
        if (!$request->user()->hasPermission('*')) {
            throw new HttpException(403);
        }

        /** @var Settings $settings */
        $settings = app(Settings::class);

        return view('admin.roles.defaults', [
            'permissionGroups' => app(PermissionCatalog::class)->groups(),
            'permissionDefaults' => $settings->get('permissions.defaults', []),
        ]);
    }

    public function store(Request $request, ?Role $role = null): RedirectResponse
    {
        $catalog = app(PermissionCatalog::class);
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'slug' => ['required', 'alpha_dash:ascii', 'max:60', Rule::unique('roles')->ignore($role?->id)],
            'position' => 'required|integer|between:0,999',
            'permissions' => 'sometimes|array',
            'permissions.*' => ['string', Rule::in($catalog->all())],
            'permission_overrides' => 'sometimes|array',
            'permission_profile' => ['sometimes', Rule::in(['none', 'member', 'mod', 'admin'])],
            'color' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/D'],
            'text_color' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/D'],
            'automatic_text_color' => ['sometimes', 'boolean'],
            'icon' => [
                'nullable', 'string', 'max:'.ContentIcons::MAX_NAME_LENGTH, 'regex:/^'.ContentIcons::NAME_PATTERN.'$/D',
            ],
        ]);
        if ($data['automatic_text_color'] ?? false) {
            $data['text_color'] = null;
        }
        unset($data['automatic_text_color']);
        $validator = Validator::make($request->all(), app(TextTranslations::class)->rules(['name' => ['max' => 60]]));
        $data += $validator->validate();
        foreach ($data['permission_overrides'] ?? [] as $permission => $choice) {
            if (
                !in_array($permission, $catalog->all(), true) ||
                !in_array($choice, ['inherit', 'allow', 'deny'], true)
            ) {
                throw ValidationException::withMessages([
                    'permission_overrides' => __('admin.members.invalid_permission_choice'),
                ]);
            }
        }
        DB::transaction(function () use ($request, $role, $data, $catalog) {
            $role = $role ? Role::query()->lockForUpdate()->findOrFail($role->id) : null;
            $data['translations'] = app(TextTranslations::class)->normalize(
                $data['translations'] ?? [],
                $role?->translations ?? [],
                ['name' => ['max' => 60]],
            );
            $actor = $request->user()->fresh();
            $hierarchy = app(RoleHierarchy::class);
            if ($role) {
                $hierarchy->assertRole($actor, $role);
                if ($role->system_key === 'member' && !$actor->hasPermission('*')) {
                    throw new HttpException(403);
                }
            }
            $data['permission_profile'] ??= $role?->permission_profile ?? 'member';
            if ($role?->system_key === 'member') {
                if ($data['permission_profile'] !== 'member') {
                    throw ValidationException::withMessages([
                        'permission_profile' => __('admin.members.default_role_unsafe'),
                    ]);
                }
                $data['permission_profile'] = 'member';
            }
            $data['permissions'] = array_values(
                array_unique(
                    array_merge(array_diff($role?->permissions ?? [], $catalog->all()), $data['permissions'] ?? []),
                ),
            );
            $overrides = $role?->permission_overrides ?? [];
            foreach ($data['permission_overrides'] ?? [] as $permission => $choice) {
                if (!($actor->hasPermission($permission) || $choice !== 'allow')) {
                    throw new HttpException(403);
                }
                $data['permissions'] = array_values(array_diff($data['permissions'], [$permission]));
                if ($choice === 'inherit') {
                    unset($overrides[$permission]);
                } else {
                    $overrides[$permission] = $choice === 'allow';
                }
            }
            $data['permission_overrides'] = $overrides;
            if (!$role && $data['slug'] === 'owner') {
                throw new HttpException(403);
            }
            if ($role?->system_key === 'owner' || in_array('*', $role?->permissions ?? [], true)) {
                if (!$actor->hasPermission('*')) {
                    throw new HttpException(403);
                }
                $data['slug'] = $role->slug;
                $data['permissions'] = ['*'];
                $data['position'] = 999;
                $data['permission_overrides'] = [];
                $data['permission_profile'] = 'none';
            } else {
                $hierarchy->assertRole($actor, new Role($data));
            }
            if ($role && $role->system_key === 'member' && !app(DefaultRole::class)->safe(new Role($data))) {
                throw ValidationException::withMessages(['permissions' => __('admin.members.default_role_unsafe')]);
            }
            $record = $role ?? new Role();
            $record->fill($data)->save();
            Audit::record('role.saved', ['id' => $record->id]);
        }, 3);

        return redirect()->route('admin.roles.index')->with('status', __('admin.messages.role_saved'));
    }

    public function permissions(Request $request, Settings $settings): RedirectResponse
    {
        if (!$request->user()->hasPermission('*')) {
            throw new HttpException(403);
        }

        $data = $request->validate(['defaults' => 'present|array']);
        $catalog = app(PermissionCatalog::class);
        foreach ($data['defaults'] as $permission => $choices) {
            if (!in_array($permission, $catalog->all(), true) || !is_array($choices) || $choices === []) {
                throw ValidationException::withMessages(['defaults' => __('admin.members.invalid_permission_choice')]);
            }
            $validator = Validator::make(
                ['choices' => $choices],
                [
                    'choices' => 'array:visitor,member,mod,admin',
                    'choices.*' => ['required', Rule::in(['inherit', '0', '1'])],
                ],
            );
            $validator->validate();
        }
        DB::transaction(function () use ($request, $settings, $data) {
            $actor = $request->user()->fresh();
            $settings->refresh();
            Setting::query()->firstOrCreate(['key' => 'permissions.defaults'], ['value' => []]);
            $overrides = Setting::query()->where('key', 'permissions.defaults')->lockForUpdate()->value('value');
            foreach ($data['defaults'] as $permission => $choices) {
                if (!$actor->hasPermission($permission)) {
                    throw new HttpException(403);
                }
                foreach ($choices as $audience => $choice) {
                    if ($choice === 'inherit') {
                        unset($overrides[$permission][$audience]);
                    } else {
                        $overrides[$permission][$audience] = (string) $choice === '1';
                    }
                }
                if (empty($overrides[$permission])) {
                    unset($overrides[$permission]);
                }
            }
            $settings->set('permissions.defaults', $overrides);
            Audit::record('role.permission_defaults_changed', ['permissions' => array_keys($data['defaults'])]);
        }, 3);

        return back()->with('status', __('admin.members.permission_defaults_saved'));
    }

    public function duplicate(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'slug' => ['required', 'alpha_dash:ascii', 'max:60', Rule::unique('roles')],
        ]);
        $copy = DB::transaction(function () use ($request, $role, $data) {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            app(RoleHierarchy::class)->assertRole($request->user()->fresh(), $role);
            if (in_array('*', $role->permissions, true)) {
                throw new HttpException(403);
            }
            $copy = $role->replicate(['system_key'])->fill($data);
            $copy->save();
            Audit::record('role.duplicated', ['id' => $copy->id, 'source' => $role->id]);

            return $copy;
        }, 3);

        return redirect()->route('admin.roles.edit', $copy)->with('status', __('admin.members.role_duplicated'));
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        DB::transaction(function () use ($request, $role) {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            app(RoleHierarchy::class)->assertRole($request->user()->fresh(), $role);
            if (in_array($role->system_key, ['owner', 'member'], true) || in_array('*', $role->permissions, true)) {
                throw new HttpException(403);
            }
            ContentEntry::withTrashed()
                ->whereIn(
                    'id',
                    fn ($query) => $query
                        ->select('content_entry_id')
                        ->from('content_entry_role')
                        ->where('role_id', $role->id),
                )
                ->update(['roles_restricted' => true]);
            $role->delete();
            Audit::record('role.deleted', ['id' => $role->id]);
        }, 3);

        return back()->with('status', __('admin.members.role_deleted'));
    }
}
