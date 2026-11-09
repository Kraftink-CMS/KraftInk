@php
    $authInputPrefix ??= '';
@endphp
<p>{{ __('auth_flow.registration.complete_from_game') }}</p>
<form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
    action="{{ route('auth.registration.store') }}">
    @csrf
    <div class="form-field mb-3">
        <label class="form-label" for="{{ $authInputPrefix }}email">
            {{ __('admin.settings.identity.email') }}
        </label>
        <input class="form-control" id="{{ $authInputPrefix }}email" name="email" type="email" maxlength="255"
            autocomplete="email" value="{{ old('email') }}" required>
    </div>
    @include('default.user.auth.partials.registration_terms')
    <button class="btn btn-primary" type="submit">{{ __('auth_flow.game.finish') }}</button>
</form>
