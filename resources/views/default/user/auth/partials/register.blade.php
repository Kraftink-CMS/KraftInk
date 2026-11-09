@use('App\Kraft\Auth\AccountIdentity')
@use('App\Kraft\Auth\AccountPolicy')
@use('App\Kraft\Auth\InGameSignIn\InGameSignInPolicy')
@php
    $authInputPrefix ??= '';
    $usernamePresentation = AccountIdentity::presentation();
    $registrationEmailRequired = app(AccountPolicy::class)->requiresRegistrationEmail();
    $gameSignIn = app(InGameSignInPolicy::class)->available();
@endphp
<div data-login-options>
    @if ($gameSignIn)
        @include('default.user.auth.partials.game-login', ['registering' => true])
    @endif
    <div data-password-login>
        <p class="auth-intro">{{ __('site.auth.register.help') }}</p>
        <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post" action="{{ route('register.store') }}">
            @csrf
            <div class="form-field mb-3">
                <label class="form-label" for="{{ $authInputPrefix }}username">{{ __('site.auth.account.username') }}</label>
                <input class="form-control" id="{{ $authInputPrefix }}username" name="username" value="{{ old('username') }}" required
                    minlength="2" maxlength="40" pattern="{{ $usernamePresentation['pattern'] }}"
                    autocomplete="username" autocapitalize="none" spellcheck="false" @if (empty($inAuthModal)) autofocus @endif>
                <div class="form-text">
                    {{ $usernamePresentation['help'] }}
                </div>
            </div>
            <div class="form-field mb-3">
                <label class="form-label" for="{{ $authInputPrefix }}email">
                    {{ __('admin.settings.identity.email') }}
                    @unless ($registrationEmailRequired)
                        <small class="text-body-secondary">({{ __('auth_flow.email.optional') }})</small>
                    @endunless
                </label>
                <input class="form-control" id="{{ $authInputPrefix }}email" type="email" name="email" value="{{ old('email') }}"
                    autocomplete="email" @required($registrationEmailRequired)>
            </div>
            <div class="form-field mb-3">
                <label class="form-label" for="{{ $authInputPrefix }}password">{{ __('site.auth.password.label') }}</label>
                <input class="form-control" id="{{ $authInputPrefix }}password" type="password" name="password" minlength="12"
                    autocomplete="new-password" required>
                <div class="form-text">{{ __('site.auth.password.minimum_help') }}</div>
            </div>
            <div class="form-field mb-3">
                <label class="form-label" for="{{ $authInputPrefix }}password_confirmation">{{ __('site.auth.password.confirm') }}</label>
                <input class="form-control" id="{{ $authInputPrefix }}password_confirmation" type="password" name="password_confirmation"
                    autocomplete="new-password" required>
            </div>
            @include('default.user.auth.partials.registration_terms')
            @include('default.user.auth.partials.captcha', ['captchaAction' => 'register'])
            <button type="submit" class="button btn btn-primary">{{ __('site.auth.register.submit') }} ↗</button>
        </form>
    </div>
    <div data-game-login-alternatives>
        @if ($gameSignIn)
            <button type="button" class="btn btn-link px-0 mt-2" data-login-switch
                data-standard-label="{{ __('auth_flow.registration.standard') }}"
                data-game-label="{{ __('auth_flow.registration.game') }}" hidden>
                {{ __('auth_flow.registration.standard') }}
            </button>
        @endif
        @include('default.user.auth.partials.oauth-buttons')
        <p class="form-foot text-center mt-4">{{ __('site.auth.register.has_account') }} <a
                href="{{ route('login') }}">{{ __('site.auth.login.title') }}</a></p>
    </div>
</div>
