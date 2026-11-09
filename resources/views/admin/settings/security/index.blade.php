@extends('admin.layouts.layout')
@section('title', __('admin.settings.security.title'))
@section('description', __('admin.settings.security.description'))
@section('content')
    @php
        $captchaProvider = old('provider', $settings['security.captcha.provider'] ?? 'none');
    @endphp
    <form data-ajax="true" method="post" action="{{ route('admin.settings.security.update') }}" class="row g-4">
        @csrf
        @include('partials.forms.sensitive-fields')
        <div class="col-12 d-flex flex-column gap-4">
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.members.two_factor') }}</h2>
                </div>
                <div class="card-body">
                    <input type="hidden" name="admin_two_factor" value="0">
                    <div class="form-check">
                        <input class="form-check-input" id="admin_two_factor" type="checkbox" name="admin_two_factor"
                            value="1" @checked(old('admin_two_factor', $settings['security.admin_two_factor'] ?? false))>
                        <label class="form-check-label"
                            for="admin_two_factor">{{ __('admin.settings.security.admin_two_factor') }}</label>
                    </div>
                    <p class="form-text mb-0">{{ __('admin.settings.security.admin_two_factor_help') }}</p>
                    @unless (auth()->user()->two_factor_confirmed_at)
                        <p class="mt-3 mb-0"><a
                                href="{{ route('account.index') }}#two-factor">{{ __('admin.settings.security.enable_yours_first') }}</a>
                        </p>
                    @endunless
                </div>
            </section>
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.settings.security.captcha') }}</h2>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="provider">{{ __('admin.settings.security.provider') }}</label>
                        <select class="form-select" name="provider" id="provider" data-fields-target="captcha-settings">
                            <option value="none" @selected($captchaProvider === 'none')>{{ __('admin.settings.security.none') }}
                            </option>
                            @foreach ($providers as $id => $provider)
                                <option value="{{ $id }}" data-show-fields="1" @selected($captchaProvider === $id)>
                                    {{ $provider['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <fieldset id="captcha-settings" @if (!isset($providers[$captchaProvider])) hidden disabled @endif>
                        <p class="text-body-secondary">{{ __('admin.settings.security.captcha_help') }}</p>
                        <div class="mb-3">
                            <label class="form-label" for="site_key">{{ __('admin.settings.security.site_key') }}</label>
                            <input class="form-control" id="site_key" name="site_key"
                                value="{{ old('site_key', $settings['security.captcha.site_key'] ?? '') }}" maxlength="200"
                                autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"
                                for="captcha_secret">{{ __('admin.settings.security.secret') }}</label>
                            <input class="form-control" id="captcha_secret" type="password" name="captcha_secret"
                                maxlength="1000" autocomplete="new-password">
                            <div class="form-text">
                                {{ __('admin.settings.security.secret_help') }}
                            </div>
                        </div>
                        @foreach (['login', 'register'] as $action)
                            <input type="hidden" name="captcha_{{ $action }}" value="0">
                            <div class="form-check mb-2">
                                <input class="form-check-input" id="captcha_{{ $action }}" type="checkbox"
                                    name="captcha_{{ $action }}" value="1" @checked(old('captcha_' . $action, $settings['security.captcha.' . $action] ?? false))>
                                <label class="form-check-label"
                                    for="captcha_{{ $action }}">{{ __('admin.settings.security.' . $action) }}</label>
                            </div>
                        @endforeach
                        <p class="form-text">{{ __('admin.settings.security.api_help') }}</p>
                        <p class="form-text mb-0">{{ __('admin.settings.security.privacy_help') }}</p>
                    </fieldset>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                </div>
            </section>
        </div>
    </form>
@endsection
