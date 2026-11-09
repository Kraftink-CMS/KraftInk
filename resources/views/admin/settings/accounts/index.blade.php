@use('App\Kraft\LocalePolicy')
@extends('admin.layouts.layout')
@section('title', __('auth_flow.admin.heading'))
@section('description', __('auth_flow.admin.description'))
@section('content')
    @include('admin.settings.accounts.partials.navigation', ['selected' => 'general'])
    @php
        $localePolicy = app(LocalePolicy::class);
        $translationEnabled = $localePolicy->allowsChoice();
        $termLocales = $translationEnabled
            ? config('kraft.locales')
            : [$localePolicy->defaultLocale() => config('kraft.locales')[$localePolicy->defaultLocale()]];
    @endphp
    <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.settings.accounts.update') }}"
        @if ($gameAccess) data-game-form @endif>
        @csrf
        @include('admin.settings.accounts.partials.auth', ['settings' => $values])
        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-8">
                <section class="card h-100 mb-0">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('auth_flow.admin.passwords') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-5">
                                <label class="form-label"
                                    for="minimum">{{ __('admin.settings.security.policy.minimum') }}</label>
                                <input class="form-control" id="minimum" type="number" name="minimum" min="12"
                                    max="128" value="{{ old('minimum', $values['auth.password.min'] ?? 12) }}"
                                    required>
                            </div>
                            <div class="col-12 col-md-7">
                                <label class="form-label"
                                    for="hash_driver">{{ __('admin.settings.accounts.passwords.driver') }}</label>
                                <select class="form-select" id="hash_driver" name="hash_driver"
                                    data-fields-target="account-hash-confirmation" aria-describedby="hash-driver-help">
                                    @foreach ($algorithms as $driver => $label)
                                        <option value="{{ $driver }}"
                                            data-show-fields="{{ $driver !== $hashDriver ? '1' : '0' }}"
                                            @selected($selectedHashDriver === $driver)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text" id="hash-driver-help">
                                    {{ __('admin.settings.accounts.passwords.help') }}
                                </div>
                            </div>
                        </div>
                        <fieldset class="d-none" id="account-hash-confirmation"
                            @if (!$hashChanged) hidden disabled @endif>
                            @include('partials.forms.confirm-action')
                        </fieldset>
                        <div class="vstack gap-2 mt-3">
                            @foreach (['mixed_case', 'symbols'] as $field)
                                <input type="hidden" name="{{ $field }}" value="0">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" id="rule_{{ $field }}" role="switch"
                                        type="checkbox" name="{{ $field }}" value="1"
                                        @checked(old($field, $values['auth.password.' . $field] ?? false))>
                                    <label class="form-check-label"
                                        for="rule_{{ $field }}">{{ __('admin.settings.security.policy.' . $field) }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            </div>
            <div class="col-12 col-xl-4">
                <section class="card h-100 mb-0">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('auth_flow.admin.profile') }}</h2>
                    </div>
                    <div class="card-body vstack gap-3">
                        @foreach (['username', 'deletion'] as $feature)
                            <div>
                                <input type="hidden" name="allow_{{ $feature }}" value="0">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                        id="allow_{{ $feature }}" name="allow_{{ $feature }}" value="1"
                                        @checked(old('allow_' . $feature, $policy->allows($feature)))
                                        @if ($feature === 'deletion') aria-describedby="account-deletion-help" @endif>
                                    <label class="form-check-label"
                                        for="allow_{{ $feature }}">{{ __('admin.settings.accounts.profile.allow_' . $feature) }}</label>
                                </div>
                                @if ($feature === 'deletion')
                                    <div class="form-text" id="account-deletion-help">
                                        {{ __('admin.settings.accounts.profile.deletion_help') }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
            <div class="col-12">
                <section class="card mb-0">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('admin.settings.accounts.terms.title') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="terms_url">{{ __('admin.settings.accounts.terms.url') }}</label>
                            <input class="form-control" type="url" id="terms_url" name="terms_url"
                                value="{{ old('terms_url', $values['account.terms_url'] ?? '') }}" maxlength="2000"
                                placeholder="https://">
                            <div class="form-text">
                                {{ __('admin.settings.accounts.terms.url_help') }}
                            </div>
                        </div>
                        <p class="text-body-secondary">{{ __('admin.settings.accounts.terms.help') }}</p>
                        @if ($translationEnabled)
                            <p class="text-body-secondary">{{ __('common.translations.help') }}</p>
                        @endif
                        <div @class(['accordion' => $translationEnabled])>
                            @foreach ($termLocales as $locale => $label)
                                @php
                                    $termValue = old('terms.' . $locale, $values['account.terms'][$locale] ?? '') ?? '';
                                    $termValue = is_string($termValue) ? $termValue : '';
                                    $termContent = html_entity_decode(
                                        strip_tags($termValue, '<img><hr>'),
                                        ENT_QUOTES | ENT_HTML5,
                                        'UTF-8',
                                    );
                                    $termFilled = preg_match('/[^\s\p{Z}]/u', $termContent) === 1;
                                    $termInvalid = $errors->has('terms.' . $locale);
                                @endphp
                                <div @class(['accordion-item' => $translationEnabled]) data-completion-group>
                                    @if ($translationEnabled)
                                        <h3 class="accordion-header">
                                            <button @class(['accordion-button', 'collapsed' => !$termInvalid]) type="button"
                                                id="terms-heading-{{ $locale }}" data-bs-toggle="collapse"
                                                data-bs-target="#terms-panel-{{ $locale }}"
                                                aria-controls="terms-panel-{{ $locale }}"
                                                aria-expanded="{{ $termInvalid ? 'true' : 'false' }}">
                                                <span class="d-flex flex-wrap align-items-center gap-2">
                                                    <span>{{ $label }}</span>
                                                    <span @class([
                                                        'badge',
                                                        'text-bg-success' => $termFilled,
                                                        'text-bg-secondary' => !$termFilled,
                                                    ]) data-completion-status
                                                        data-filled-label="{{ __('common.translations.filled') }}"
                                                        data-empty-label="{{ __('common.translations.empty') }}"
                                                        aria-live="polite">{{ __($termFilled ? 'common.translations.filled' : 'common.translations.empty') }}</span>
                                                </span>
                                            </button>
                                        </h3>
                                    @endif
                                    <div @class([
                                        'accordion-collapse collapse' => $translationEnabled,
                                        'show' => $translationEnabled && $termInvalid,
                                    ]) id="terms-panel-{{ $locale }}"
                                        @if ($translationEnabled) aria-labelledby="terms-heading-{{ $locale }}" @endif>
                                        <div @class(['accordion-body' => $translationEnabled])>
                                            <label class="form-label" for="terms_{{ $locale }}">
                                                {{ __('admin.settings.accounts.terms.text') }} <span
                                                    class="text-body-secondary">{{ __('common.labels.optional') }}</span>
                                            </label>
                                            <textarea @class(['form-control', 'is-invalid' => $termInvalid]) id="terms_{{ $locale }}" name="terms[{{ $locale }}]"
                                                lang="{{ $locale }}" rows="5" maxlength="30000" data-editor="tinymce"
                                                @if ($termInvalid) aria-invalid="true" aria-describedby="terms-error-{{ $locale }}" @endif>{{ $termValue }}</textarea>
                                            @error('terms.' . $locale)
                                                <div class="invalid-feedback" id="terms-error-{{ $locale }}">
                                                    {{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            </div>
        </div>
        @if ($gameAccess)
            <section class="mt-4" aria-labelledby="game-access-heading">
                <h2 class="h5 mb-3" id="game-access-heading">{{ __('auth_flow.admin.game_settings') }}</h2>
                @foreach ($gameAccess as $id => $game)
                    <input type="hidden" name="auth_game" value="{{ $id }}" data-active-game>
                    <fieldset class="border-0 p-0 m-0" data-game-settings="{{ $id }}">
                        @include('admin.games.partials.settings-fields', [
                            'fieldValues' => $game['setting_values'],
                        ])
                    </fieldset>
                @endforeach
            </section>
        @endif
        <div class="mt-3">
            <button class="btn btn-primary" type="submit">{{ __('admin.settings.save') }}</button>
        </div>
    </form>
@endsection
