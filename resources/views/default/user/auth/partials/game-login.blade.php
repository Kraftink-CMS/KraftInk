@use('App\Kraft\Auth\Passkeys')
@use('App\Kraft\Auth\InGameSignIn\InGameSignInPolicy')
<div data-game-login hidden
    data-start-url="{{ route('in-game-sign-in.start') }}"
    data-status-url="{{ route('in-game-sign-in.status', ['id' => '__challenge__']) }}"
    data-resend-url="{{ route('in-game-sign-in.resend', ['id' => '__challenge__']) }}"
    data-cancel-url="{{ route('in-game-sign-in.cancel', ['id' => '__challenge__']) }}"
    data-finish-url="{{ route('in-game-sign-in.finish', ['id' => '__challenge__']) }}"
    data-game-copy="{{ json_encode(__('auth_flow.game'), JSON_THROW_ON_ERROR) }}">
    <form data-game-login-start>
        <p class="auth-intro">{{ __(empty($registering) ? 'auth_flow.game.intro' : 'auth_flow.registration.intro') }}</p>
        <div class="mb-3">
            <label class="form-label" for="{{ $authInputPrefix }}game-login-name">{{ __('auth_flow.game.username') }}</label>
            <input class="form-control" id="{{ $authInputPrefix }}game-login-name" name="username" maxlength="40"
                autocomplete="{{ empty($registering) && app(Passkeys::class)->available(request()) ? 'username webauthn' : 'username' }}"
                autocapitalize="none" spellcheck="false" required>
        </div>
        @include('default.user.auth.partials.captcha', ['captchaAction' => app(InGameSignInPolicy::class)->captchaAction()])
        <button class="btn btn-primary w-100" type="submit">
            <i class="bi bi-controller me-1" aria-hidden="true"></i> {{ __('auth_flow.game.send') }}
        </button>
        @if ($capabilities->canRegister)
            @include('default.user.auth.partials.registration_terms', ['consentOnContinue' => true])
        @endif
        <p class="text-danger small mt-2 mb-0" role="alert" data-game-login-error hidden></p>
    </form>
    <section data-game-login-step hidden>
        <div data-game-login-waiting>
            <p class="text-body-secondary">{{ __('auth_flow.game.match') }}</p>
            <div class="auth-game-number" data-game-login-number aria-live="polite"></div>
        </div>
        <p class="small text-body-secondary mt-3 mb-0" role="status" data-game-login-status></p>
        <div class="mt-3" data-game-login-actions>
            <button class="btn btn-outline-secondary w-100" type="button" data-game-login-resend disabled>
                {{ __('auth_flow.game.resend') }}
            </button>
        </div>
        <form class="mt-3" data-game-login-finish hidden>
            <div class="mb-3">
                <label class="form-label" for="{{ $authInputPrefix }}game-login-email">{{ __('admin.settings.identity.email') }}</label>
                <input class="form-control" type="email" id="{{ $authInputPrefix }}game-login-email" name="email"
                    maxlength="255" autocomplete="email" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">{{ __('auth_flow.game.finish') }}</button>
        </form>
        <p class="text-danger small mt-2 mb-0" role="alert" data-game-login-step-error hidden></p>
        <button class="btn btn-link px-0 mt-3" type="button" data-game-login-back>
            {{ __('common.actions.back') }}
        </button>
    </section>
</div>
