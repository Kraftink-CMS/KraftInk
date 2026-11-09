@php($authInputPrefix ??= '')
<p class="auth-intro">{{ __('site.auth.reset.help') }}</p>
<form data-ajax="true" method="post" action="{{ route('password.email') }}">
    @csrf
    <div class="form-field mb-3">
        <label class="form-label" for="{{ $authInputPrefix }}email">{{ __('admin.settings.identity.email') }}</label>
        <input class="form-control" id="{{ $authInputPrefix }}email" type="email" name="email" value="{{ old('email') }}"
            autocomplete="email" required @if (empty($inAuthModal)) autofocus @endif>
    </div>
    <button type="submit" class="button btn btn-primary">{{ __('site.auth.reset.submit') }} ↗</button>
</form>
<p class="form-foot text-center mt-4 mb-0"><a href="{{ route('login') }}">← {{ __('site.auth.login.back') }}</a></p>
