@php($authInputPrefix ??= '')
<form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token ?? request()->route('token') }}">
    <div class="form-field mb-3">
        <label class="form-label" for="{{ $authInputPrefix }}email">{{ __('admin.settings.identity.email') }}</label>
        <input class="form-control" id="{{ $authInputPrefix }}email" type="email" name="email"
            value="{{ old('email', $email ?? request('email')) }}" required autocomplete="email">
    </div>
    <div class="form-field mb-3">
        <label class="form-label" for="{{ $authInputPrefix }}password">{{ __('site.auth.password.new') }}</label>
        <input class="form-control" id="{{ $authInputPrefix }}password" type="password" name="password" minlength="12" required
            autocomplete="new-password">
        <div class="form-text">{{ __('site.auth.password.minimum_help') }}</div>
    </div>
    <div class="form-field mb-3">
        <label class="form-label" for="{{ $authInputPrefix }}password_confirmation">{{ __('site.auth.password.confirm') }}</label>
        <input class="form-control" id="{{ $authInputPrefix }}password_confirmation" type="password" name="password_confirmation" required
            autocomplete="new-password">
    </div>
    <button class="button btn btn-primary" type="submit">{{ __('site.auth.password.save') }} ↗</button>
</form>
