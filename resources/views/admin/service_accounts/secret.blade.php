@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('service_accounts.secret_title'))
@section('description', $account->username)
@section('actions')
    <a class="btn btn-outline-secondary"
        href="{{ route('admin.service_accounts.edit', $account) }}">{{ __('service_accounts.back') }}</a>
@endsection
@section('content')
    <section class="card">
        <div class="card-body">
            <p class="alert alert-warning">{{ __('service_accounts.secret_help') }}</p>
            <label class="form-label" for="service-token-secret">{{ __('service_accounts.secret') }}</label>
            <div class="input-group mb-3">
                <input class="form-control font-monospace" id="service-token-secret" value="{{ $raw }}" readonly
                    autocomplete="off" spellcheck="false">
                <button class="btn btn-outline-secondary" type="button" data-copy="{{ $raw }}"
                    data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
            </div>
            <p>{{ __('service_accounts.header_help') }}</p>
            <code>Authorization: Bearer &lt;{{ __('service_accounts.secret') }}&gt;</code>
            <dl class="row mb-0 mt-3">
                <dt class="col-sm-3">{{ __('service_accounts.expires') }}</dt>
                <dd class="col-sm-9">{{ app(SiteDates::class)->format($token->expires_at, 'd M Y H:i T') }}</dd>
                <dt class="col-sm-3">{{ __('service_accounts.scopes') }}</dt>
                <dd class="col-sm-9 mb-0">{{ implode(', ', $token->scopes) }}</dd>
            </dl>
        </div>
    </section>
@endsection
