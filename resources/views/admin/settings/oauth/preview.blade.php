@extends('admin.layouts.layout')
@section('title', __('admin.integrations.oauth.preview_title'))
@section('description', __('admin.integrations.oauth.preview_description'))
@section('actions')
    <a class="btn btn-outline-secondary"
        href="{{ route('admin.settings.oauth.index') }}">{{ __('common.actions.cancel') }}</a>
@endsection
@section('content')
    @include('admin.settings.accounts.partials.navigation', ['selected' => 'oauth'])
    <form data-ajax="true" data-redirect-url="response" class="card" method="post"
        action="{{ route('admin.settings.oauth.apply') }}">
        <div class="card-header">
            <h2 class="card-title">
                {{ $plan['proposal']['operation'] === 'provider' ? $registry->metadata($plan['proposal']['provider'])['name'] : __('admin.integrations.oauth.preview_global') }}
            </h2>
        </div>
        <div class="card-body">
            @if ($plan['blocked'])
                <div class="alert alert-danger">
                    <p class="fw-semibold">
                        {{ __('admin.integrations.oauth.blocked_count', ['count' => count($plan['blocked'])]) }}</p>
                    <p class="mb-0">{{ __('admin.integrations.oauth.change_blocked') }}</p>
                </div>
            @endif
            <ul class="list-group list-group-flush mb-3">
                <li class="list-group-item px-0">
                    {{ __('admin.integrations.oauth.revoked_count', ['count' => count($plan['revoked'])]) }}
                </li>
                <li class="list-group-item px-0">
                    {{ __('admin.integrations.oauth.recovery_count', ['count' => count($plan['recoveries'])]) }}</li>
                <li class="list-group-item px-0">{{ __('admin.settings.security.policy.mode') }} :
                    {{ __('admin.settings.security.policy.' . $plan['mode']) }}</li>
            </ul>
            @if ($plan['mode_changed'] && $plan['mode'] === 'mixed')
                <p class="alert alert-info">{{ __('admin.integrations.oauth.mode_changed') }}</p>
            @endif
            <p>{{ __('admin.integrations.oauth.links_preserved') }}</p>
            @if (!$plan['revoked'] && !$plan['recoveries'] && !$plan['blocked'])
                <p class="text-body-secondary">{{ __('admin.integrations.oauth.no_change') }}</p>
            @endif
            @unless ($plan['blocked'])
                @csrf
                <input type="hidden" name="preview_token" value="{{ $token }}">
                <div class="form-check mb-3">
                    <input class="form-check-input" id="confirmed" name="confirmed" type="checkbox" value="1" required>
                    <label class="form-check-label" for="confirmed">{{ __('admin.integrations.oauth.confirm') }}</label>
                </div>
                @include('partials.forms.sensitive-fields', [
                    'user' => auth()->user(),
                    'formKey' => 'oauth_change',
                ])
            @endunless
        </div>
        @unless ($plan['blocked'])
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ __('admin.integrations.oauth.apply') }}</button>
                <a class="btn btn-outline-secondary"
                    href="{{ route('admin.settings.oauth.index') }}">{{ __('common.actions.cancel') }}</a>
            </div>
        @endunless
    </form>
@endsection
