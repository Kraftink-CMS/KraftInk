@use('App\Kraft\LocalePolicy')
@use('App\Kraft\Site\SiteIdentity')
@extends('admin.layouts.layout')
@section('title', __('admin.settings.title'))
@section('description', __('admin.settings.description'))
@section('content')
    <form data-ajax="true" data-redirect-on-upload="true" method="post" action="{{ url('/admin/settings/site') }}"
        enctype="multipart/form-data">
        @csrf
        <div class="row g-4 align-items-start">
            <div class="col-lg-8 d-flex flex-column gap-4">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('admin.settings.identity.title') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="site_name">{{ __('admin.settings.identity.name') }}</label>
                            <input class="form-control" id="site_name" name="site_name"
                                value="{{ old('site_name', $settings['site.name'] ?? 'KraftInk') }}" required
                                maxlength="120">
                        </div>
                        <div class="mb-3" data-site-url>
                            @php
                                $siteUrlValue = old('site_url', $settings['site.url'] ?? config('app.url'));
                            @endphp
                            @php
                                $siteUrlValue = is_string($siteUrlValue) ? $siteUrlValue : config('app.url');
                            @endphp
                            <label class="form-label" for="site_url">{{ __('admin.settings.identity.public_url') }}</label>
                            <input class="form-control" id="site_url" name="site_url" inputmode="url"
                                value="{{ $siteUrlValue }}" data-current-url="{{ config('app.url') }}" required
                                maxlength="2048" aria-describedby="site_url_help">
                            <div class="form-text" id="site_url_help">
                                {{ __('admin.settings.identity.url_help') }}
                            </div>
                            <fieldset class="d-none" data-site-url-proof
                                @if ($siteUrlValue === config('app.url')) hidden disabled @endif>
                                @include('partials.forms.confirm-action', ['confirmationId' => 'site-url'])
                            </fieldset>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="copyright">{{ __('admin.settings.identity.copyright') }}</label>
                            <input class="form-control" id="copyright" name="copyright"
                                value="{{ is_string(old('copyright', $settings['site.copyright'] ?? '')) ? old('copyright', $settings['site.copyright'] ?? '') : '' }}"
                                maxlength="300" aria-describedby="copyright_help">
                            <div class="form-text" id="copyright_help">
                                {{ __('admin.settings.identity.copyright_help') }}
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"
                                for="site_description">{{ __('admin.settings.identity.introduction') }}</label>
                            <textarea class="form-control" id="site_description" name="site_description" rows="3" maxlength="500">{{ old('site_description', $settings['site.description'] ?? '') }}</textarea><small
                                class="form-text">{{ __('admin.settings.identity.introduction_help') }}</small>
                        </div>
                        <div class="row g-3">
                            <div class="mb-3 col-md-6">
                                <label class="form-label"
                                    for="contact_email">{{ __('admin.settings.identity.contact_email') }}</label>
                                <input class="form-control" id="contact_email" type="email" name="contact_email"
                                    value="{{ old('contact_email', $settings['contact.email'] ?? '') }}">
                            </div>
                        </div>
                    </div>
                </section>
                @if (app(LocalePolicy::class)->allowsChoice())
                    <section class="card">
                        <div class="card-body">
                            @php
                                $siteTextFields = SiteIdentity::textFields();
                                unset($siteTextFields['seo_title'], $siteTextFields['seo_description']);
                                foreach (
                                    [
                                        'name' => 'admin.settings.identity.name',
                                        'description' => 'admin.settings.identity.introduction',
                                        'copyright' => 'admin.settings.identity.copyright',
                                    ]
                                    as $field => $label
                                ) {
                                    $siteTextFields[$field]['label'] = __($label);
                                }
                            @endphp
                            <x-admin::translation-fields id="site-text-translations" :fields="$siteTextFields" :values="$settings['site.translations'] ?? []"
                                prefix="site_translations" />
                        </div>
                    </section>
                @endif
            </div>
            <div class="col-lg-4 d-flex flex-column gap-4">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('common.dates.language_timezone') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <x-forms.timezone-select :value="$settings['site.timezone'] ?? 'UTC'" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="site_locale">{{ __('admin.settings.language.default') }}</label>
                            <select class="form-select" id="site_locale" name="locale" required>
                                @foreach (config('kraft.locales') as $code => $label)
                                    <option value="{{ $code }}" @selected(old('locale', $settings['site.locale'] ?? app(LocalePolicy::class)->defaultLocale()) === $code)>{{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                {{ __('admin.settings.language.default_help') }}
                            </div>
                        </div>
                        <input type="hidden" name="locale_user_choice" value="0">
                        <div class="form-check">
                            <input class="form-check-input" id="locale_user_choice" type="checkbox"
                                name="locale_user_choice" value="1" @checked(old('locale_user_choice', $settings['locale.user_choice'] ?? false))>
                            <label class="form-check-label"
                                for="locale_user_choice">{{ __('admin.settings.language.allow_choice') }}</label>
                        </div>
                        <div class="form-text">
                            {{ __('admin.settings.language.choice_help') }}
                        </div>
                    </div>
                </section>
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('admin.settings.appearance.heading') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            @if (count($appearanceModes) > 1)
                                <label class="form-label"
                                    for="site_appearance">{{ __('admin.settings.appearance.site_mode') }}</label>
                                <select class="form-select" id="site_appearance" name="site_appearance" required
                                    aria-describedby="site_appearance_help">
                                    <option value="user" @selected(old('site_appearance', $appearancePolicy['choice']) === 'user')>
                                        {{ __('admin.settings.appearance.visitor_choice') }}</option>
                                    @foreach ($appearanceModes as $mode)
                                        <option value="{{ $mode }}" @selected(old('site_appearance', $appearancePolicy['choice']) === $mode)>
                                            {{ __('admin.settings.appearance.' . $mode) }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text" id="site_appearance_help">
                                    {{ __('admin.settings.appearance.policy_help') }}</div>
                                @error('site_appearance')
                                    <p class="text-danger small mt-2">{{ $message }}</p>
                                @enderror
                            @else
                                <p class="form-label mb-2">{{ __('admin.settings.appearance.site_mode') }}</p>
                                <p class="mb-2"><span
                                        class="badge text-bg-secondary">{{ __('admin.settings.appearance.' . $appearancePolicy['forced']) }}</span>
                                </p>
                                <p class="form-text mb-0">
                                    {{ __('admin.settings.appearance.single_mode_help', ['preference' => __($appearancePolicy['choice'] === 'user' ? 'admin.settings.appearance.visitor_choice' : 'admin.settings.appearance.' . $appearancePolicy['choice'])]) }}
                                </p>
                            @endif
                        </div>
                        <x-admin::media-picker name="logo_id" :value="$selectedLogo ?? null" profile="logo" context="site-logo"
                            upload-name="logo_upload" :label="__('admin.settings.identity.logo')" :help="__('admin.settings.identity.logo_help')" :empty-label="__('admin.settings.identity.default_logo')"
                            class="mb-4" />
                        <x-admin::media-picker name="favicon_id" :value="$selectedFavicon ?? null" profile="favicon"
                            context="site-favicon" upload-name="favicon_upload" :label="__('admin.settings.identity.favicon.title')" :help="__('admin.settings.identity.favicon.upload_help')"
                            :empty-label="__('admin.settings.identity.favicon.default')" />
                    </div>
                </section>
            </div>
        </div>
        <div class="mt-4">
            <button class="btn btn-primary" type="submit">{{ __('admin.settings.save') }}</button>
        </div>
    </form>
@endsection
