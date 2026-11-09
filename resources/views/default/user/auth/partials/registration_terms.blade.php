@php
    $consentOnContinue ??= false;
@endphp
@if ($terms['text'])
    <details class="border rounded p-3 mb-3">
        <summary>{{ __('site.auth.register.read_terms') }}</summary>
        <div class="prose mt-3">
            {!! $terms['text'] !!}
        </div>
    </details>
@endif
@if ($consentOnContinue)
    <input type="hidden" name="terms" value="1">
    <p class="form-text mt-2 mb-0">
@else
    <label class="d-flex align-items-start gap-2 form-label mb-3">
        <input class="form-check-input" type="checkbox" name="terms" value="1" required @checked(old('terms'))>
        <span>
@endif
        @if ($customTerms)
            {{ __($consentOnContinue ? 'auth_flow.registration.consent_custom' : 'site.auth.register.accept_terms') }}
            @if ($terms['url'])
                <a href="{{ $terms['url'] }}" target="_blank"
                    rel="noopener noreferrer">{{ __('site.auth.register.read_terms') }}</a>
            @endif
        @else
            {{ __($consentOnContinue ? 'auth_flow.registration.consent' : 'site.auth.register.accept') }}
            @if ($capabilities->hasRules)
                <a href="{{ url('/p/rules') }}" target="_blank">{{ __('common.labels.rules_lower') }}</a>
            @else
                {{ __('common.labels.rules_lower') }}
            @endif
            {{ __('site.auth.register.and') }}
            @if ($capabilities->hasPrivacy)
                <a href="{{ url('/p/privacy') }}" target="_blank">{{ __('site.auth.register.privacy_policy') }}</a>.
            @else
                {{ __('site.auth.register.privacy_policy') }}.
            @endif
        @endif
@if ($consentOnContinue)
    </p>
@else
        </span>
    </label>
@endif
