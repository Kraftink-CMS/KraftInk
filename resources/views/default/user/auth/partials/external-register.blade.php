@use('App\Kraft\Auth\AccountIdentity')
@php
    $authInputPrefix ??= '';
    $username = AccountIdentity::presentation();
@endphp
<p class="auth-intro">{{ __('site.auth.security.complete_help') }}</p>
<form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('auth.registration.external.store') }}">
    @csrf
    <div class="form-field mb-3">
        <label class="form-label" for="{{ $authInputPrefix }}username">{{ __('site.auth.account.username') }}</label>
        <input class="form-control" id="{{ $authInputPrefix }}username" name="username"
            value="{{ old('username', $proof['username']) }}" required minlength="2" maxlength="40"
            pattern="{{ $username['pattern'] }}" autocomplete="username" autocapitalize="none" spellcheck="false">
        <div class="form-text">{{ $username['help'] }}</div>
    </div>
    <div class="form-field mb-3">
        <label class="form-label" for="{{ $authInputPrefix }}email">
            {{ __('admin.settings.identity.email') }}
            @unless ($emailRequired)
                <small class="text-body-secondary">({{ __('auth_flow.email.optional') }})</small>
            @endunless
        </label>
        <input class="form-control" id="{{ $authInputPrefix }}email" type="email" name="email"
            value="{{ old('email', $proof['email']) }}" @required($emailRequired) maxlength="255" autocomplete="email">
    </div>
    @if ($passwordRequired)
        <div class="form-field mb-3">
            <label class="form-label" for="{{ $authInputPrefix }}password">{{ __('site.auth.password.label') }}</label>
            <input class="form-control" id="{{ $authInputPrefix }}password" type="password" name="password" required
                minlength="12" autocomplete="new-password">
            <div class="form-text">{{ __('site.auth.password.minimum_help') }}</div>
        </div>
        <div class="form-field mb-3">
            <label class="form-label" for="{{ $authInputPrefix }}password_confirmation">{{ __('site.auth.password.confirm') }}</label>
            <input class="form-control" id="{{ $authInputPrefix }}password_confirmation" type="password"
                name="password_confirmation" required autocomplete="new-password">
        </div>
    @endif
    @include('default.user.auth.partials.registration_terms')
    @include('default.user.auth.partials.captcha', ['captchaAction' => 'register'])
    <button class="button btn btn-primary" type="submit">{{ __('site.auth.security.submit') }}</button>
</form>
