@use('App\Kraft\Auth\BuiltinRoles')
@use('App\Kraft\Auth\PermissionCatalog')
@use('App\Models\Accounts\Role')
@extends('admin.layouts.layout')
@section('title', $editing ? __('admin.members.edit_role') . ' · ' . $editing->name : __('admin.members.new_role'))
@section('description', __('admin.members.rank_help'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.roles.index') }}">{{ __('admin.members.roles') }}</a>
@endsection
@section('content')
    <section class="card">
        <form data-ajax="true" @unless ($editing) data-redirect-url="response" @endunless method="post"
            action="{{ route('admin.roles.store', ['role' => $editing?->id]) }}">
            @csrf
            <div class="card-body">
                @php
                    $ownerRole = $editing?->system_key === 'owner' || in_array('*', $editing?->permissions ?? [], true);
                    $permissionCatalog = app(PermissionCatalog::class);
                    $profile = old('permission_profile', $editing?->permission_profile ?? 'member');
                    $profile = in_array($profile, ['none', 'member', 'mod', 'admin'], true) ? $profile : 'none';
                    $oldOverrides = old('permission_overrides', []);
                    $oldOverrides = is_array($oldOverrides) ? $oldOverrides : [];
                    $profileDefaults = [];
                    foreach (['none', 'member', 'mod', 'admin'] as $profileKey) {
                        $profileDefaults[$profileKey] = $permissionCatalog->defaults($profileKey);
                    }
                    $automaticTextColor = (bool) old('automatic_text_color', !$editing?->text_color);
                    $backgroundColor = old(
                        'color',
                        $editing?->color ?? BuiltinRoles::DEFAULT_COLORS['member'],
                    );
                    $backgroundColor =
                        is_string($backgroundColor) && preg_match('/^#[a-fA-F0-9]{6}$/D', $backgroundColor)
                            ? $backgroundColor
                            : BuiltinRoles::DEFAULT_COLORS['member'];
                    $textColor = old('text_color', $editing?->text_color ?? '#ffffff');
                    $textColor =
                        is_string($textColor) && preg_match('/^#[a-fA-F0-9]{6}$/D', $textColor)
                            ? $textColor
                            : '#ffffff';
                    $previewRole = new Role([
                        'name' => old('name', $editing?->name) ?: __('admin.members.new_role'),
                        'icon' => old('icon', $editing?->icon),
                        'color' => $backgroundColor,
                        'text_color' => $automaticTextColor ? null : $textColor,
                    ]);
                @endphp
                <div data-role-appearance>
                    <div class="mb-3">
                        <label class="form-label" for="role_name">{{ __('admin.members.role_name') }}</label>
                        <input class="form-control" id="role_name" name="name" value="{{ old('name', $editing?->name) }}"
                            required maxlength="60">
                    </div>
                    <x-admin::translation-fields id="role-translations" :fields="['name' => ['label' => __('common.labels.name'), 'max' => 60]]" :values="$editing?->translations ?? []" />
                    <div class="row g-3">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="role_slug">{{ __('common.labels.identifier') }}</label>
                            <input class="form-control" id="role_slug" name="slug"
                                value="{{ old('slug', $editing?->slug) }}" required pattern="[a-zA-Z0-9_-]+" maxlength="60"
                                @readonly($ownerRole)>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="role_position">{{ __('admin.members.role_priority') }}</label>
                            <input class="form-control" id="role_position" type="number" name="position" min="0"
                                max="999" value="{{ old('position', $ownerRole ? 999 : $editing?->position ?? 0) }}"
                                required @readonly($ownerRole)>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="role_color">{{ __('admin.members.background_color') }}</label>
                            <input class="form-control form-control-color" id="role_color" name="color" type="color"
                                value="{{ $backgroundColor }}" aria-describedby="role_color_help">
                            <div class="form-text" id="role_color_help">{{ __('admin.members.color_help') }}</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="role_text_color">{{ __('admin.members.text_color') }}</label>
                            <input class="form-control form-control-color" id="role_text_color" name="text_color"
                                type="color" value="{{ $textColor }}" aria-describedby="role_text_color_help">
                            <div class="form-check mt-2">
                                <input type="hidden" name="automatic_text_color" value="0">
                                <input class="form-check-input" id="role_automatic_text_color" name="automatic_text_color"
                                    type="checkbox" value="1" @checked($automaticTextColor)>
                                <label class="form-check-label"
                                    for="role_automatic_text_color">{{ __('admin.members.automatic_text_color') }}</label>
                            </div>
                            <div class="form-text" id="role_text_color_help">{{ __('admin.members.text_color_help') }}
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="role_icon">{{ __('admin.members.icon') }}</label>
                        <input class="form-control" id="role_icon" name="icon"
                            value="{{ old('icon', $editing?->icon) }}" pattern="[a-z][a-z0-9-]*" maxlength="60">
                        <div class="form-text">
                            {{ __('admin.members.icon_help') }}
                        </div>
                    </div>
                    <div class="rounded border p-3 mb-3">
                        <div class="small text-body-secondary mb-2">{{ __('admin.members.role_preview') }}</div>
                        <div data-role-badge-preview data-placeholder="{{ __('admin.members.new_role') }}">
                            @include('partials.users.role-badge', ['role' => $previewRole])
                        </div>
                        <p class="small text-warning-emphasis mt-2 mb-0" data-role-contrast-warning hidden>
                            {{ __('admin.members.text_color_contrast_warning') }}</p>
                    </div>
                </div>
                @if ($ownerRole)
                    <p class="form-text">{{ __('admin.members.protected_owner') }}</p>
                @else
                    <div class="mb-3">
                        <label class="form-label"
                            for="permission_profile">{{ __('admin.members.permission_profile') }}</label>
                        <select class="form-select" id="permission_profile" name="permission_profile"
                            @disabled($editing?->system_key === 'member')>
                            @foreach (['none', 'member', 'mod', 'admin'] as $option)
                                <option value="{{ $option }}" @selected($profile === $option)>
                                    {{ __('admin.members.profile_' . $option) }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ __('admin.members.permission_profile_help') }}</div>
                    </div>
                    <fieldset>
                        <legend class="fs-6 fw-semibold">{{ __('admin.members.role_permissions') }}</legend>
                        <p class="form-text">{{ __('admin.members.permission_toggle_help') }}</p>
                        <p class="form-text">{{ __('admin.members.permission_inheritance_help') }}</p>
                        <div class="row row-cols-1 row-cols-xl-2 g-3">
                            @foreach ($permissionGroups as $group)
                                @php
                                    $groupPermissions = array_values(
                                        array_filter(
                                            $group['permissions'],
                                            fn($permission) => auth()->user()->hasPermission($permission),
                                        ),
                                    );
                                @endphp
                                @if ($groupPermissions)
                                    <div class="col-12 w-100">
                                        <div
                                            class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom pb-2">
                                            <h3 class="fs-6 fw-semibold mb-0" data-permission-group="{{ $group['id'] }}">
                                                {{ $group['label'] }}</h3>
                                            <div class="d-flex flex-wrap gap-2">
                                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                                    data-permission-bulk="allow"
                                                    data-permission-target="{{ $group['id'] }}">{{ __('admin.members.allow_all') }}</button>
                                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                                    data-permission-bulk="deny"
                                                    data-permission-target="{{ $group['id'] }}">{{ __('admin.members.deny_all') }}</button>
                                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                                    data-permission-bulk="inherit"
                                                    data-permission-target="{{ $group['id'] }}">{{ __('admin.members.reset_permissions') }}</button>
                                            </div>
                                        </div>
                                    </div>
                                    @foreach ($groupPermissions as $permission)
                                        @php
                                            $choice = array_key_exists($permission, $oldOverrides)
                                                ? $oldOverrides[$permission]
                                                : (array_key_exists($permission, $editing?->permission_overrides ?? [])
                                                    ? ($editing->permission_overrides[$permission]
                                                        ? 'allow'
                                                        : 'deny')
                                                    : (in_array($permission, $editing?->permissions ?? [], true)
                                                        ? 'allow'
                                                        : 'inherit'));
                                        @endphp
                                        <div class="col">
                                            <x-admin::permissions.toggle :name="'permission_overrides[' . $permission . ']'" :value="$choice" :label="$permissionCatalog->label($permission)"
                                                :group="$group['id']" :provided="in_array($permission, $profileDefaults[$profile], true)" :profile-defaults="array_map(
                                                    fn($permissions) => in_array($permission, $permissions, true),
                                                    $profileDefaults,
                                                )" />
                                        </div>
                                    @endforeach
                                @endif
                            @endforeach
                        </div>
                    </fieldset>
                @endif
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                <a class="btn btn-outline-secondary"
                    href="{{ route('admin.roles.index') }}">{{ __('common.actions.cancel') }}</a>
            </div>
        </form>
    </section>
    @if ($editing && !in_array('*', $editing->permissions ?? [], true) && $editing->system_key !== 'owner')
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('common.labels.actions') }}</h2>
            </div>
            <div class="card-body">
                <details>
                    <summary>{{ __('admin.members.duplicate') }}</summary>
                    <form data-ajax="true" data-redirect-url="response" class="mt-3" method="post"
                        action="{{ route('admin.roles.duplicate', $editing) }}">
                        @csrf
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label"
                                    for="copy_name">{{ __('admin.members.duplicate_name') }}</label>
                                <input class="form-control" id="copy_name" name="name" maxlength="60" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label"
                                    for="copy_slug">{{ __('admin.members.duplicate_slug') }}</label>
                                <input class="form-control" id="copy_slug" name="slug" maxlength="60"
                                    pattern="[a-zA-Z0-9_-]+" required>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-outline-primary"
                                    type="submit">{{ __('admin.members.duplicate') }}</button>
                            </div>
                        </div>
                    </form>
                </details>
                @if ($editing->system_key !== 'member')
                    <hr>
                    <form data-ajax="true" data-redirect-url="response" method="post"
                        action="{{ route('admin.roles.destroy', $editing) }}"
                        data-confirm="{{ __('admin.members.delete_role_help') }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-outline-danger"
                            type="submit">{{ __('admin.members.delete_role') }}</button>
                    </form>
                @else
                    <p class="form-text mt-3 mb-0">{{ __('admin.members.protected_member') }}</p>
                @endif
            </div>
        </section>
    @endif
@endsection
