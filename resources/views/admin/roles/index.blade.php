@extends('admin.layouts.layout')
@section('title', __('admin.members.roles_title'))
@section('description', __('admin.members.rank_help'))
@section('actions')
    <a class="btn btn-primary" href="{{ route('admin.roles.create') }}">
        {{ __('admin.members.new_role') }}</a>
@endsection
@section('content')
    <div id="admin-roles-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <x-admin::tables.paginated :paginator="$roles">
            <table class="table align-middle mb-0">
                <thead class="text-nowrap">
                    <tr>
                        <th data-table-sort="name">{{ __('common.labels.role') }}</th>
                        <th data-table-sort="position">{{ __('admin.members.role_priority') }}</th>
                        <th>{{ __('admin.members.permission_profile') }}</th>
                        <th data-table-sort="users">{{ __('admin.members.role_members') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($roles as $role)
                        <tr>
                            <td>
                                @include('partials.users.role-badge', ['role' => $role])
                                <small class="d-block text-body-secondary mt-1">{{ $role->slug }}</small>
                            </td>
                            <td>{{ $role->position }}</td>
                            <td>{{ __('admin.members.profile_' . ($role->system_key === 'owner' ? 'owner' : $role->permission_profile ?? 'member')) }}
                            </td>
                            <td>{{ $role->users_count }}</td>
                            <td class="text-end">
                                @if ($hierarchy->canManageRole(auth()->user(), $role) && ($role->system_key !== 'member' || auth()->user()->hasPermission('*')))
                                    <a class="btn btn-sm btn-outline-secondary"
                                        href="{{ route('admin.roles.edit', $role) }}">{{ __('common.actions.edit') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-5 text-center">
                                <p>{{ __('admin.members.no_roles') }}</p>
                                <a class="btn btn-primary"
                                    href="{{ route('admin.roles.create') }}">{{ __('admin.members.new_role') }}</a>
                            </td>
                        </tr>
                    @endforelse
                    <tr>
                        <td>{{ __('admin.members.profile_visitor') }}<small
                                class="d-block text-body-secondary mt-1">{{ __('admin.members.visitor_help') }}</small></td>
                        <td>—</td>
                        <td>{{ __('admin.members.profile_visitor') }}</td>
                        <td>—</td>
                        <td class="text-end">
                            @if (auth()->user()->hasPermission('*'))
                                <a class="btn btn-sm btn-outline-secondary"
                                    href="{{ route('admin.roles.defaults.index') }}">{{ __('common.actions.edit') }}</a>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </x-admin::tables.paginated>
        <p class="text-body-secondary">{{ __('admin.members.member_assignment_help') }}</p>
    </div>
@endsection
