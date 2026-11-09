@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', $account ? __('service_accounts.edit') . ' · ' . $account->username : __('service_accounts.add'))
@section('description', __('service_accounts.identity_help'))
@section('actions')
    <a class="btn btn-outline-secondary"
        href="{{ route('admin.service_accounts.index') }}">{{ __('service_accounts.back_list') }}</a>
@endsection
@section('content')
    @if (!$apiEnabled)
        <div class="alert alert-info mb-0">
            {{ __('service_accounts.api_disabled') }}
            @if (auth()->user()->hasPermission('settings.manage'))
                <a href="{{ route('admin.settings.api.index') }}">{{ __('service_accounts.settings') }}</a>
            @endif
        </div>
    @endif
    <form data-ajax="true" data-redirect-url="response" class="card" method="post"
        action="{{ $account ? route('admin.service_accounts.update', $account) : route('admin.service_accounts.store') }}">
        @csrf
        <input type="hidden" name="_form" value="{{ $account ? 'service_identity' : 'service_create' }}">
        <div class="card-header">
            <h2 class="card-title">{{ __('service_accounts.identity') }}</h2>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="service-username">{{ __('service_accounts.username') }}</label>
                <input class="form-control" id="service-username" name="username" value="{{ old('username', $account?->username) }}"
                    maxlength="60" required>
                @error('username')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3">
                <label class="form-label" for="service-description">{{ __('service_accounts.purpose') }}</label>
                <textarea class="form-control" id="service-description" name="description" rows="3" maxlength="2000"
                    aria-describedby="service-description-help">{{ old('description', $account?->service_description) }}</textarea>
                <div class="form-text" id="service-description-help">{{ __('service_accounts.purpose_help') }}</div>
            </div>
            @if ($account)
                <p class="small text-body-secondary mb-0">{{ __('service_accounts.owner') }} :
                    {{ $owner?->username ?? __('service_accounts.unknown_owner') }} · {{ __('service_accounts.created_at') }}
                    {{ app(SiteDates::class)->format($account->created_at, 'd M Y H:i T') }}</p>
            @else
                <fieldset class="mb-3">
                    <legend class="h6 float-none">{{ __('service_accounts.scopes') }}</legend>
                    <p class="form-text">{{ __('service_accounts.scopes_help') }}</p>
                    @include('admin.service_accounts.partials.scopes', [
                        'scopeId' => 'service-create',
                        'scopeForm' => 'service_create',
                        'selectedScopes' => [],
                    ])
                </fieldset>
                @include('partials.forms.sensitive-fields', [
                    'user' => auth()->user(),
                    'formKey' => 'service_create',
                ])
            @endif
        </div>
        <div class="card-footer">
            <button class="btn btn-primary" type="submit"
                @disabled(!$account && !$definitions)>{{ $account ? __('common.actions.save') : __('service_accounts.add') }}</button>
        </div>
    </form>
    @if ($account)
        <form data-ajax="true" data-redirect-url="response" class="card" method="post" action="{{ route('admin.service_accounts.scopes', $account) }}">
            @csrf
            <input type="hidden" name="_form" value="service_scopes">
            <div class="card-header">
                <h2 class="card-title">{{ __('service_accounts.scopes') }}</h2>
            </div>
            <div class="card-body">
                <p class="text-body-secondary">{{ __('service_accounts.scopes_help') }}</p>
                @include('admin.service_accounts.partials.scopes', [
                    'scopeId' => 'service-grants',
                    'scopeForm' => 'service_scopes',
                    'selectedScopes' => $account->service_scopes ?? [],
                ])
                <p class="form-text">{{ __('service_accounts.confirmation_help') }}</p>
                @include('partials.forms.sensitive-fields', [
                    'user' => auth()->user(),
                    'formKey' => 'service_grants',
                    'confirmationRequired' => false,
                ])
            </div>
            <div class="card-footer"><button class="btn btn-primary"
                    type="submit">{{ __('common.actions.save') }}</button></div>
        </form>
        <div id="admin-service-account-tokens" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('service_accounts.tokens') }}</h2>
                </div>
                <x-tables.controls :paginator="$tokens" container-class="card-body" />
                <div class="card-body p-0 table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="text-nowrap">
                            <tr>
                                <th scope="col" data-table-sort="name">{{ __('service_accounts.token_name') }}</th>
                                <th scope="col">{{ __('service_accounts.token_state') }}</th>
                                <th scope="col" data-table-sort="expires_at">{{ __('service_accounts.expires') }}</th>
                                <th scope="col" data-table-sort="last_used_at">{{ __('service_accounts.last_used') }}
                                </th>
                                <th scope="col" class="text-end">{{ __('common.labels.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($tokens as $token)
                                @php($activeToken = !$token->revoked_at && $token->expires_at->isFuture())
                                <tr>
                                    <td>{{ $token->name }}<small
                                            class="d-block text-body-secondary">{{ implode(', ', $token->scopes) }}</small>
                                    </td>
                                    <td><span
                                            class="badge {{ $activeToken ? 'text-bg-success' : 'text-bg-secondary' }}">{{ __($token->revoked_at ? 'service_accounts.revoked_state' : ($token->expires_at->isPast() ? 'service_accounts.expired' : 'service_accounts.active')) }}</span>
                                    </td>
                                    <td class="text-nowrap">
                                        {{ app(SiteDates::class)->format($token->expires_at, 'd M Y H:i T') }}</td>
                                    <td>{{ $token->last_used_at ? app(SiteDates::class)->format($token->last_used_at, 'd M Y H:i T') : __('service_accounts.never') }}
                                    </td>
                                    <td class="text-end">
                                        @if ($activeToken)
                                            <div class="d-flex flex-wrap justify-content-end gap-2">
                                                @if (!$account->service_disabled_at && $apiEnabled)
                                                    <button class="btn btn-outline-secondary btn-sm" type="button"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#service-rotate-{{ $token->id }}">{{ __('service_accounts.rotate') }}</button>
                                                @endif
                                                <form data-ajax="true" data-redirect-url="response" method="post"
                                                    action="{{ route('admin.service_accounts.tokens.revoke', [$account, $token]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-outline-danger btn-sm"
                                                        type="submit">{{ __('service_accounts.revoke') }}</button>
                                                </form>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-tables.empty :colspan="5" :message="__('service_accounts.no_tokens')" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    {{ $tokens->links() }}
                    @if ($tokens->contains(fn($token) => !$token->revoked_at && $token->expires_at->isFuture()))
                        <form data-ajax="true" data-redirect-url="response" class="mt-3" method="post"
                            action="{{ route('admin.service_accounts.tokens.revoke', $account) }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-outline-danger"
                                type="submit">{{ __('service_accounts.revoke_all') }}</button>
                        </form>
                    @endif
                </div>
            </section>
            @foreach ($tokens as $token)
                @if (!$token->revoked_at && $token->expires_at->isFuture() && !$account->service_disabled_at && $apiEnabled)
                    <x-forms.modal :id="'service-rotate-' . $token->id" :title="__('service_accounts.rotate')" :action="route('admin.service_accounts.tokens.rotate', [$account, $token])" :submit="__('service_accounts.rotate')">
                        <p>{{ __('service_accounts.rotate_help') }}</p>
                        @include('partials.forms.sensitive-fields', [
                            'user' => auth()->user(),
                            'formKey' => 'service_rotate_' . $token->id,
                        ])
                    </x-forms.modal>
                @endif
            @endforeach
        </div>

        @if (!$account->service_disabled_at && $apiEnabled)
            <form data-ajax="true" data-redirect-url="response" class="card" method="post" action="{{ route('admin.service_accounts.tokens.store', $account) }}">
                @csrf
                <input type="hidden" name="_form" value="service_token">
                <div class="card-header">
                    <h2 class="card-title">{{ __('service_accounts.create_token') }}</h2>
                </div>
                <div class="card-body">
                    <p class="text-body-secondary">{{ __('service_accounts.token_help') }}</p>
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label"
                                for="service-token-name">{{ __('service_accounts.token_name') }}</label>
                            <input class="form-control" id="service-token-name" name="token_name"
                                value="{{ old('token_name') }}" maxlength="120" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"
                                for="service-token-lifetime">{{ __('service_accounts.lifetime') }}</label>
                            <input class="form-control" id="service-token-lifetime" name="lifetime" type="number"
                                min="15" max="{{ min(43200, max(15, $lifetime)) }}"
                                value="{{ old('lifetime', min(43200, max(15, $lifetime))) }}" required
                                aria-describedby="service-token-lifetime-help">
                            <div class="form-text" id="service-token-lifetime-help">
                                {{ __('service_accounts.lifetime_help') }}</div>
                        </div>
                    </div>
                    <fieldset class="mb-3">
                        <legend class="h6 float-none">{{ __('service_accounts.scopes') }}</legend>
                        @include('admin.service_accounts.partials.scopes', [
                            'scopeId' => 'service-token',
                            'scopeForm' => 'service_token',
                            'definitions' => array_intersect_key(
                                $definitions,
                                array_flip($account->service_scopes ?? [])),
                            'selectedScopes' => [],
                        ])
                    </fieldset>
                    @include('partials.forms.sensitive-fields', [
                        'user' => auth()->user(),
                        'formKey' => 'service_issue',
                    ])
                </div>
                <div class="card-footer"><button class="btn btn-primary"
                        type="submit">{{ __('service_accounts.create_token') }}</button></div>
            </form>
        @endif
        <form data-ajax="true" data-redirect-url="response" class="card" method="post" action="{{ route('admin.service_accounts.enabled', $account) }}">
            @csrf
            <input type="hidden" name="enabled" value="{{ $account->service_disabled_at ? '1' : '0' }}">
            <div class="card-header">
                <h2 class="card-title">
                    {{ __($account->service_disabled_at ? 'service_accounts.enable' : 'service_accounts.disable') }}</h2>
            </div>
            <div class="card-body">
                <p>{{ __('service_accounts.disable_help') }}</p>
                <div class="form-check mb-3">
                    <input class="form-check-input" id="service-confirm-state" name="confirmed" type="checkbox"
                        value="1" required>
                    <label class="form-check-label"
                        for="service-confirm-state">{{ __($account->service_disabled_at ? 'service_accounts.confirm_enable' : 'service_accounts.confirm_disable') }}</label>
                </div>
                @if ($account->service_disabled_at)
                    @include('partials.forms.sensitive-fields', [
                        'user' => auth()->user(),
                        'formKey' => 'service_enable',
                    ])
                @endif
            </div>
            <div class="card-footer"><button
                    class="btn {{ $account->service_disabled_at ? 'btn-primary' : 'btn-outline-danger' }}"
                    type="submit">{{ __($account->service_disabled_at ? 'service_accounts.enable' : 'service_accounts.disable') }}</button>
            </div>
        </form>
    @endif
@endsection
