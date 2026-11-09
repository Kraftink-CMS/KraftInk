@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('admin.servers.accounts.title', ['server' => $server->name]))
@section('description', __('admin.servers.accounts.description'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.servers.link.show', $server) }}">
        {{ __('admin.servers.link.manage') }}</a>
    @if ($scopes)
        <button class="btn btn-primary" type="button" data-bs-toggle="modal"
            data-bs-target="#account-token-create">{{ __('admin.servers.accounts.add') }}</button>
    @endif
@endsection
@section('content')
    @php
        $checkedScopes = array_filter((array) old('scopes', []), 'is_string');
    @endphp
    @if (session('link.accounts.token'))
        <div class="alert alert-warning">
            <p>{{ __('admin.servers.accounts.token_once') }}</p>
            <code class="text-break">{{ session('link.accounts.token') }}</code>
        </div>
    @endif
    <section class="card">
        <div class="card-body">
            <p>{{ __('admin.servers.accounts.security_help') }}</p>
            <code>{{ url('/api/link/accounts') }}</code>
        </div>
    </section>
    <div id="admin-link-account-tokens" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <x-tables.controls :paginator="$tokens" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th data-table-sort="name">{{ __('admin.servers.accounts.name') }}</th>
                            <th>{{ __('admin.servers.accounts.scopes_title') }}</th>
                            <th>{{ __('common.labels.status') }}</th>
                            <th data-table-sort="expires_at">{{ __('admin.servers.accounts.expires') }}</th>
                            <th data-table-sort="last_used_at">{{ __('admin.servers.accounts.last_used') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tokens as $token)
                            <tr>
                                <td>{{ $token->name ?: __('admin.servers.accounts.revoked_state') }}</td>
                                <td>
                                    <ul class="list-unstyled small mb-0">
                                        @foreach ($token->scopes as $scope)
                                            <li>{{ __('admin.servers.accounts.scopes.' . $scope) }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td><span
                                        class="badge text-bg-{{ $token->revoked_at || $token->expires_at->isPast() ? 'secondary' : 'success' }}">{{ $token->revoked_at ? __('admin.servers.accounts.revoked_state') : ($token->expires_at->isPast() ? __('admin.servers.accounts.expired') : __('admin.servers.accounts.active')) }}</span>
                                </td>
                                <td>{{ app(SiteDates::class)->format($token->expires_at) }}</td>
                                <td>{{ $token->last_used_at ? app(SiteDates::class)->format($token->last_used_at) : __('admin.servers.accounts.never') }}
                                </td>
                                <td>
                                    @if (!$token->revoked_at)
                                        <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal"
                                            data-bs-target="#revoke-{{ $token->id }}">{{ __('admin.servers.accounts.revoke') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="6" :message="__('admin.servers.accounts.empty')" :description="__('admin.servers.accounts.empty_help')" :action="$scopes ? __('admin.servers.accounts.add') : null"
                                :modal="$scopes ? 'account-token-create' : null" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $tokens->links() }}
            </div>
        </section>
        @foreach ($tokens as $token)
            @if (!$token->revoked_at)
                <x-forms.modal :id="'revoke-' . $token->id" :title="__('admin.servers.accounts.revoke')" :action="route('admin.servers.link.accounts.revoke', ['server' => $server, 'token' => $token->id])" method="DELETE" :submit="__('admin.servers.accounts.revoke')">
                    <p>{{ __('admin.servers.accounts.revoke_help') }}</p>
                </x-forms.modal>
            @endif
        @endforeach
    </div>
    @if ($scopes)
        <x-forms.modal id="account-token-create" :title="__('admin.servers.accounts.add')" :action="route('admin.servers.link.accounts.issue', $server)" :submit="__('admin.servers.accounts.add')" size="lg">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="access-name">{{ __('admin.servers.accounts.name') }}</label>
                    <input class="form-control" id="access-name" name="name" value="{{ old('name') }}" maxlength="120"
                        required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="access-days">{{ __('admin.servers.accounts.duration') }}</label>
                    <input class="form-control" id="access-days" name="days" type="number" min="1" max="90"
                        value="{{ old('days', 30) }}" required>
                </div>
            </div>
            <fieldset class="my-3">
                <legend class="form-label fs-6">{{ __('admin.servers.accounts.scopes_title') }}</legend>
                <p class="form-text">{{ __('admin.servers.accounts.scopes_help') }}</p>
                @foreach ($scopes as $scope)
                    <label class="form-check d-block">
                        <input class="form-check-input" type="checkbox" name="scopes[]" value="{{ $scope }}"
                            @checked(in_array($scope, $checkedScopes, true))>
                        <span class="form-check-label">{{ __('admin.servers.accounts.scopes.' . $scope) }}</span></label>
                @endforeach
            </fieldset>
            @include('partials.forms.confirm-action', ['confirmationId' => 'account-token-create'])
        </x-forms.modal>
    @endif
@endsection
