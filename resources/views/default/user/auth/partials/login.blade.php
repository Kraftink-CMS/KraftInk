@use('App\Kraft\Auth\InGameSignIn\InGameSignInPolicy')
@use('App\Kraft\Auth\Passkeys')
@php
    $authInputPrefix ??= '';
    $gameSignIn = app(InGameSignInPolicy::class)->available();
@endphp
<div data-login-options>
    @if ($gameSignIn)
        @include('default.user.auth.partials.game-login')
    @endif
    @if ($capabilities->canLoginLocally)
        <div data-password-login>
            <p class="auth-intro">{{ __('site.auth.login.help') }}</p>
            <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
                action="{{ route('login') }}">
                @csrf
                <div class="form-field mb-3">
                    <label class="form-label" for="{{ $authInputPrefix }}login">{{ __('site.auth.login.identifier') }}</label>
                    <input class="form-control" id="{{ $authInputPrefix }}login" type="text" name="login" value="{{ old('login') }}"
                        autocomplete="{{ app(Passkeys::class)->available(request()) ? 'username webauthn' : 'username' }}"
                        required @if (!$gameSignIn && empty($inAuthModal)) autofocus @endif>
                </div>
                <div class="form-field mb-3">
                    <label class="form-label" for="{{ $authInputPrefix }}password">{{ __('site.auth.password.label') }}</label>
                    <input class="form-control" id="{{ $authInputPrefix }}password" type="password" name="password"
                        autocomplete="current-password" required>
                </div>
                <div class="form-between flex-wrap align-items-center">
                    <label class="check-label d-flex align-items-start gap-2 form-label">
                        <input class="form-check-input" name="remember" type="checkbox" value="1">
                        {{ __('site.auth.login.remember') }}</label>
                    <a href="{{ route('password.request') }}">{{ __('site.auth.reset.link') }}</a>
                </div>
                @include('default.user.auth.partials.captcha', ['captchaAction' => 'login'])
                <button type="submit" class="button btn btn-primary">{{ __('site.auth.login.submit') }} ↗</button>
            </form>
        </div>
    @endif
    <div data-game-login-alternatives>
        @if ($capabilities->canLoginLocally && $gameSignIn)
            <button type="button" class="btn btn-link px-0 mt-2" data-login-switch
                data-standard-label="{{ __('auth_flow.game.password') }}"
                data-game-label="{{ __('auth_flow.game.use_game') }}" hidden>
                {{ __('auth_flow.game.password') }}
            </button>
        @endif
        @include('default.user.auth.passkeys.login')
        @include('default.user.auth.partials.oauth-buttons')
        @if ($capabilities->canRegister)
            <p class="form-foot text-center mt-4 mb-0">{{ __('site.auth.login.no_account') }} <a
                    href="{{ route('register') }}">{{ __('site.auth.register.title') }}</a></p>
        @endif
    </div>
</div>
