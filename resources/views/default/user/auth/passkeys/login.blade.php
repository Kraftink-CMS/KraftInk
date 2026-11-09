@if (app(\App\Kraft\Auth\Passkeys::class)->available(request()))
    <form class="mt-3" data-ajax="false" data-passkey-login hidden method="post"
        action="{{ route('auth.passkeys.login') }}" data-options="{{ route('auth.passkeys.options') }}"
        data-error="{{ __('passkeys.invalid') }}">
        @csrf
        <button class="btn btn-outline-primary w-100" type="submit">
            <i class="bi bi-key me-1" aria-hidden="true"></i>{{ __('passkeys.sign_in') }}
        </button>
        <p class="alert alert-danger mt-2 mb-0" data-passkey-error role="alert" hidden></p>
    </form>
@endif
