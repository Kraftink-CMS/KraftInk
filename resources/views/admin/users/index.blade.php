@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('common.labels.members'))
@section('description', __('admin.members.description'))
@section('actions')
    @if (auth()->user()->hasPermission('users.create') && auth()->user()->hasPermission('users.edit-personal'))
        <a class="btn btn-primary" href="{{ route('admin.users.create') }}">
            {{ __('admin.members.create') }}</a>
    @endif
@endsection
@section('content')
    <div id="admin-users-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-body">
                <x-tables.controls :paginator="$users" class="mb-3" />
                <form method="get" action="{{ route('admin.users.index') }}" class="row g-2">
                    <div class="col-md-5">
                        <label class="visually-hidden" for="members_search">{{ __('common.actions.search') }}</label>
                        <input class="form-control" id="members_search" name="q" value="{{ request('q') }}"
                            placeholder="{{ __('admin.members.search') }}" maxlength="100">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="status" aria-label="{{ __('admin.members.status') }}">
                            <option value="">{{ __('admin.members.all_statuses') }}</option>
                            @foreach (['active', 'banned', 'unverified', 'closed'] as $state)
                                <option value="{{ $state }}" @selected(request('status') === $state)>{{ __('admin.members.' . $state) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="role" aria-label="{{ __('admin.members.roles') }}">
                            <option value="">{{ __('admin.members.all_roles') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected((int) request('role') === $role->id)>{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <noscript>
                        <div class="col-md-1">
                            <button class="btn btn-primary w-100" type="submit" aria-label="{{ __('common.actions.search') }}"><i
                                    class="bi bi-search" aria-hidden="true"></i></button>
                        </div>
                    </noscript>
                </form>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th data-table-sort="username">{{ __('common.labels.member') }}</th>
                            <th>{{ __('admin.members.roles') }}</th>
                            <th>{{ __('admin.members.status') }}</th>
                            <th data-table-sort="last_login_at">{{ __('admin.members.last_login') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $member)
                            <tr>
                                <td><strong>{{ $member->username }}</strong>
                                    @if (auth()->user()->hasPermission('users.view-personal'))
                                        <small class="d-block text-body-secondary">{{ $member->email }}</small>
                                    @endif
                                </td>
                                <td>
                                    @foreach ($member->roles as $role)
                                        @include('partials.users.role-badge', ['role' => $role])
                                    @endforeach
                                </td>
                                <td>
                                    @include('admin.users.partials.status')
                                </td>
                                <td>{{ app(SiteDates::class)->format($member->last_login_at, 'd/m/Y H:i T') ?? '—' }}
                                </td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm"
                                        href="{{ route('admin.users.edit', $member) }}">{{ __('common.actions.edit') }}</a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-5 text-body-secondary">
                                    {{ __('admin.members.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $users->links() }}
            </div>
        </section>
    </div>
@endsection
