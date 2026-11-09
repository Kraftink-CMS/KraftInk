@use('App\Kraft\SiteDates')
@extends('default.layouts.layout')
@section('title', __('api.created'))
@section('content')
    <header class="page-heading">
        <h1>{{ __('api.created') }}</h1>
    </header>
    <section class="card">
        <div class="card-body">
            <h2 class="h5">{{ $token->name }}</h2>
            <p>{{ __('api.copy_once') }}</p>
            <label class="form-label" for="created-api-token">{{ __('api.secret') }}</label>
            <textarea class="form-control font-monospace" id="created-api-token" readonly rows="3" spellcheck="false" autocomplete="off">{{ $raw }}</textarea>
            <p class="small text-body-secondary mt-3 mb-0">{{ __('admin.integrations.auth_api.expires') }} {{ app(SiteDates::class)->format($token->expires_at, 'd M Y H:i T') }}</p>
        </div>
        <div class="card-footer">
            <a class="btn btn-primary" href="{{ route('account.tokens.index') }}">{{ __('api.back') }}</a>
        </div>
    </section>
@endsection
