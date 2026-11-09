@php
    $loginModes = ['local', 'mixed', 'external'];
    $loginMode = old('auth_mode', $settings['auth.mode'] ?? 'mixed');
    $loginMode = in_array($loginMode, $loginModes, true) ? $loginMode : 'mixed';
    $emailRequired = (bool) old('email_required', $policy->requiresRegistrationEmail());
    $verificationRequired = (bool) old('email_verification_required', $policy->requiresVerifiedEmail());
    $externalPasswordAllowed = (bool) old('external_password_allowed', $policy->allowsExternalPassword());
    $externalPasswordRequired = (bool) old('external_password_required', $policy->requiresExternalPassword());
@endphp
<section class="card mb-3" id="connexion-inscription" aria-labelledby="site-auth-heading">
    <div class="card-header">
        <h2 class="card-title" id="site-auth-heading">{{ __('auth_flow.admin.access') }}</h2>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="mode">{{ __('admin.settings.security.policy.mode') }}</label>
                <select class="form-select" id="mode" name="auth_mode" aria-describedby="mode-help"
                    data-fields-target="external-account-settings" data-select-help="mode-help">
                    @foreach ($loginModes as $mode)
                        <option value="{{ $mode }}" data-show-fields="{{ $mode === 'local' ? '0' : '1' }}"
                            data-help="{{ __('auth_flow.admin.mode_help.' . $mode) }}" @selected($loginMode === $mode)>
                            {{ __('admin.settings.security.policy.' . $mode) }}</option>
                    @endforeach
                </select>
                <div class="form-text" id="mode-help" aria-live="polite">
                    {{ __('auth_flow.admin.mode_help.' . $loginMode) }}
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="auth-form-presentation">{{ __('auth_flow.admin.presentation') }}</label>
                <select class="form-select" id="auth-form-presentation" name="auth_form_presentation"
                    aria-describedby="auth-form-presentation-help">
                    @foreach (['page', 'modal'] as $presentation)
                        <option value="{{ $presentation }}" @selected(old('auth_form_presentation', $settings['auth.form_presentation'] ?? 'page') === $presentation)>
                            {{ __('auth_flow.admin.' . $presentation) }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text" id="auth-form-presentation-help">{{ __('auth_flow.admin.help') }}</div>
            </div>
            <div class="col-md-6">
                <div>
                    <input type="hidden" name="registration_enabled" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="registration-enabled"
                            name="registration_enabled" value="1" aria-describedby="registration-enabled-help"
                            @checked(old('registration_enabled', $settings['registration.enabled'] ?? true))>
                        <label class="form-check-label"
                            for="registration-enabled">{{ __('admin.settings.accounts.allow_registration') }}</label>
                    </div>
                    <div class="form-text" id="registration-enabled-help">
                        {{ __('admin.settings.accounts.registration_help') }}</div>
                </div>
                <div class="mt-3">
                    <input type="hidden" name="email_required" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="registration-email-required"
                            name="email_required" value="1" aria-describedby="registration-email-help"
                            data-fields-target="email-verification-settings" aria-controls="email-verification-settings"
                            @checked($emailRequired)>
                        <label class="form-check-label"
                            for="registration-email-required">{{ __('auth_flow.admin.email') }}</label>
                    </div>
                    <div class="form-text" id="registration-email-help">{{ __('auth_flow.admin.email_help') }}</div>
                    <fieldset class="border-0 border-start ps-3 pe-0 py-0 ms-4 mt-2" id="email-verification-settings"
                        @if (!$emailRequired) hidden disabled @endif>
                        <input type="hidden" name="email_verification_required" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch"
                                id="email-verification-required" name="email_verification_required" value="1"
                                aria-describedby="email-verification-help" @checked($verificationRequired)>
                            <label class="form-check-label"
                                for="email-verification-required">{{ __('auth_flow.admin.verify_email') }}</label>
                        </div>
                        <div class="form-text" id="email-verification-help">
                            {{ __('auth_flow.admin.verify_email_help') }}</div>
                        @error('email_verification_required')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </fieldset>
                </div>
            </div>
            <div class="col-md-6">
                <input type="hidden" name="passkeys_enabled" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="passkeys_enabled"
                        id="passkeys-enabled" value="1" aria-describedby="passkeys-enabled-help"
                        @checked(old('passkeys_enabled', $settings['auth.passkeys_enabled'] ?? true))>
                    <label class="form-check-label" for="passkeys-enabled">{{ __('auth_flow.admin.passkeys') }}</label>
                </div>
                <div class="form-text" id="passkeys-enabled-help">{{ __('auth_flow.admin.passkeys_help') }}</div>
                <fieldset class="border-0 p-0 mt-3" id="external-account-settings"
                    @if ($loginMode === 'local') hidden disabled @endif>
                    <legend class="float-none w-auto p-0 fs-6 fw-semibold mb-2">
                        {{ __('auth_flow.admin.external_accounts') }}
                    </legend>
                    <input type="hidden" name="external_password_allowed" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                            id="external-password-allowed" name="external_password_allowed" value="1"
                            data-fields-target="external-password-settings" aria-controls="external-password-settings"
                            @checked($externalPasswordAllowed)>
                        <label class="form-check-label"
                            for="external-password-allowed">{{ __('auth_flow.admin.external_password_allowed') }}</label>
                    </div>
                    <fieldset class="border-0 border-start ps-3 pe-0 py-0 ms-4 mt-2" id="external-password-settings"
                        @if (!$externalPasswordAllowed) hidden disabled @endif>
                        <input type="hidden" name="external_password_required" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch"
                                id="external-password-required" name="external_password_required" value="1"
                                @checked($externalPasswordRequired)>
                            <label class="form-check-label"
                                for="external-password-required">{{ __('auth_flow.admin.external_password_required') }}</label>
                        </div>
                    </fieldset>
                </fieldset>
            </div>
        </div>
    </div>
</section>
