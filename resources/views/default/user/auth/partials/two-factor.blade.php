@php($authInputPrefix ??= '')
<p class="auth-intro">{{ __('site.auth.two_factor.challenge_help') }}</p>
<form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('auth.two_factor.verify') }}">
    @csrf
    <div class="form-field mb-3">
        <label class="form-label" for="{{ $authInputPrefix }}code">{{ __('site.auth.two_factor.code') }}</label>
        <input class="form-control" id="{{ $authInputPrefix }}code" name="code" autocomplete="one-time-code" required
            maxlength="32" @if (empty($inAuthModal)) autofocus @endif>
    </div>
    <button class="button btn btn-primary" type="submit">{{ __('site.auth.two_factor.verify') }} ↗</button>
</form>
