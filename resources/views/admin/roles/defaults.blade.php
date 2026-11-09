@use('App\Kraft\Auth\PermissionCatalog')
@extends('admin.layouts.layout')
@section('title', __('admin.members.visitor_permissions'))
@section('description', __('admin.members.visitor_permissions_help'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.roles.index') }}">{{ __('admin.members.roles') }}</a>
@endsection
@section('content')
    @php
        $catalog = app(PermissionCatalog::class);
        $definitions = $catalog->definitions();
        $oldDefaults = old('defaults', []);
        $oldDefaults = is_array($oldDefaults) ? $oldDefaults : [];
    @endphp
    <p class="text-body-secondary">{{ __('admin.members.permission_toggle_help') }}</p>
    <form data-ajax="true" class="vstack gap-4" method="post" action="{{ route('admin.roles.defaults.update') }}">
        @csrf
        @foreach ($permissionGroups as $group)
            <section class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h2 class="card-title">{{ $group['label'] }}</h2>
                    <div class="d-flex flex-wrap gap-2 ms-auto">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-permission-bulk="1" data-permission-target="{{ $group['id'] }}">{{ __('admin.members.allow_all') }}</button>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-permission-bulk="0" data-permission-target="{{ $group['id'] }}">{{ __('admin.members.deny_all') }}</button>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-permission-bulk="inherit" data-permission-target="{{ $group['id'] }}">{{ __('admin.members.reset_permissions') }}</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row row-cols-1 row-cols-xl-2 g-3">
                        @foreach ($group['permissions'] as $permission)
                            @php
                                $choice = $oldDefaults[$permission]['visitor'] ?? (array_key_exists('visitor', $permissionDefaults[$permission] ?? []) ? ($permissionDefaults[$permission]['visitor'] ? '1' : '0') : 'inherit');
                                $provided = $definitions[$permission]['default']['visitor'] ?? false;
                            @endphp
                            <div class="col">
                                <x-admin::permissions.toggle :name="'defaults['.$permission.'][visitor]'" :value="$choice"
                                    :label="$catalog->label($permission)" :group="$group['id']" :provided="$provided"
                                    deny-value="0" allow-value="1" />
                            </div>
                        @endforeach
                    </div>
                </div>
                @if ($loop->last)
                    <div class="card-footer d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                        <a class="btn btn-outline-secondary" href="{{ route('admin.roles.index') }}">{{ __('common.actions.cancel') }}</a>
                    </div>
                @endif
            </section>
        @endforeach
    </form>
@endsection
