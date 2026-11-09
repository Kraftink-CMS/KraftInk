@use('App\Kraft\LocalePolicy')
@extends('admin.layouts.layout')
@section('title', __('admin.home.title'))
@section('description', __('admin.home.description'))
@section('content')
    @php
        $localePolicy = app(LocalePolicy::class);
        $siteLocale = $localePolicy->defaultLocale();
        $translationsEnabled = $localePolicy->allowsChoice();
        $homeLocales = $translationsEnabled
            ? config('kraft.locales')
            : [$siteLocale => config('kraft.locales')[$siteLocale]];
    @endphp
    <form data-ajax="true" data-redirect-on-upload="true" method="post" action="{{ route('admin.home.store') }}"
        enctype="multipart/form-data" class="vstack gap-4">
        @csrf
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.home.heading') }}</h2>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="title">
                        {{ __('common.labels.title') }}
                        @if ($translationsEnabled)
                            · {{ config('kraft.locales')[$siteLocale] }}
                        @endif
                    </label>
                    <input class="form-control" id="title" name="title" maxlength="160"
                        value="{{ old('title', $values['title']) }}">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="description">
                        {{ __('common.labels.description') }}
                        @if ($translationsEnabled)
                            · {{ config('kraft.locales')[$siteLocale] }}
                        @endif
                    </label>
                    <textarea class="form-control" id="description" name="description" rows="2" maxlength="500">{{ old('description', $values['description']) }}</textarea>
                </div>
                <x-admin::translation-fields id="home-heading-translations" prefix="heading_translations"
                    :fields="$headingFields" :values="$values['heading_translations']" />
            </div>
        </section>
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.home.welcome_message') }}</h2>
            </div>
            <div class="card-body">
                @if ($translationsEnabled)
                    <p class="text-body-secondary">{{ __('admin.home.message_help') }}</p>
                @endif
                <div @class(['accordion' => $translationsEnabled])>
                    @foreach ($homeLocales as $messageLocale => $messageLocaleLabel)
                        @php
                            $messageValue = old('messages.' . $messageLocale, $values['messages'][$messageLocale] ?? '') ?? '';
                            $messageValue = is_string($messageValue) ? $messageValue : '';
                            $messageContent = html_entity_decode(
                                strip_tags($messageValue, '<img><hr>'),
                                ENT_QUOTES | ENT_HTML5,
                                'UTF-8',
                            );
                            $messageFilled = preg_match('/[^\s\p{Z}]/u', $messageContent) === 1;
                            $messageInvalid = $errors->has('messages.' . $messageLocale);
                        @endphp
                        <div @class(['accordion-item' => $translationsEnabled]) @if ($translationsEnabled) data-completion-group @endif>
                            @if ($translationsEnabled)
                                <h3 class="accordion-header">
                                    <button @class(['accordion-button', 'collapsed' => !$messageInvalid]) type="button"
                                        id="home-message-heading-{{ $messageLocale }}" data-bs-toggle="collapse"
                                        data-bs-target="#home-message-{{ $messageLocale }}"
                                        aria-controls="home-message-{{ $messageLocale }}"
                                        aria-expanded="{{ $messageInvalid ? 'true' : 'false' }}">
                                        <span class="d-flex flex-wrap align-items-center gap-2">
                                            <span>{{ $messageLocaleLabel }}</span>
                                            <span @class([
                                                'badge',
                                                'text-bg-success' => $messageFilled,
                                                'text-bg-secondary' => !$messageFilled,
                                            ]) data-completion-status
                                                data-filled-label="{{ __('common.translations.filled') }}"
                                                data-empty-label="{{ __('common.translations.empty') }}"
                                                aria-live="polite">{{ __($messageFilled ? 'common.translations.filled' : 'common.translations.empty') }}</span>
                                        </span>
                                    </button>
                                </h3>
                                <div @class(['accordion-collapse collapse', 'show' => $messageInvalid]) id="home-message-{{ $messageLocale }}"
                                    aria-labelledby="home-message-heading-{{ $messageLocale }}">
                                    <div class="accordion-body">
                            @endif
                            <label class="form-label" for="message_{{ $messageLocale }}">
                                {{ __('admin.home.welcome_message') }} <span
                                    class="text-body-secondary">{{ __('common.labels.optional') }}</span>
                            </label>
                            <textarea @class(['form-control', 'is-invalid' => $messageInvalid]) id="message_{{ $messageLocale }}" name="messages[{{ $messageLocale }}]"
                                lang="{{ $messageLocale }}" rows="6" maxlength="20000" data-editor="tinymce"
                                @if ($messageInvalid) aria-invalid="true" aria-describedby="home-message-error-{{ $messageLocale }}" @endif>{{ $messageValue }}</textarea>
                            @error('messages.' . $messageLocale)
                                <div class="invalid-feedback" id="home-message-error-{{ $messageLocale }}">
                                    {{ $message }}</div>
                            @enderror
                            @if ($translationsEnabled)
                        </div>
                </div>
                @endif
            </div>
            @endforeach
            </div>
            </div>
        </section>
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.home.guest_alert') }}</h2>
            </div>
            <div class="card-body">
                <input type="hidden" name="alert_enabled" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" id="alert_enabled" type="checkbox" name="alert_enabled"
                        value="1" @checked(old('alert_enabled', $values['alert_enabled']))>
                    <label class="form-check-label" for="alert_enabled">{{ __('admin.home.alert_enabled') }}</label>
                </div>
                <p class="text-body-secondary">
                    {{ __('admin.home.' . ($translationsEnabled ? 'alert_help' : 'alert_help_single')) }}</p>
                <div @class(['accordion' => $translationsEnabled])>
                    @foreach ($homeLocales as $alertLocale => $alertLocaleLabel)
                        @php
                            $alertValue = old('alerts.' . $alertLocale, $values['alerts'][$alertLocale] ?? '') ?? '';
                            $alertValue = is_string($alertValue) ? $alertValue : '';
                            $alertContent = html_entity_decode(
                                strip_tags($alertValue, '<img><hr>'),
                                ENT_QUOTES | ENT_HTML5,
                                'UTF-8',
                            );
                            $alertFilled = preg_match('/[^\s\p{Z}]/u', $alertContent) === 1;
                            $alertInvalid = $errors->has('alerts.' . $alertLocale);
                        @endphp
                        <div @class(['accordion-item' => $translationsEnabled]) @if ($translationsEnabled) data-completion-group @endif>
                            @if ($translationsEnabled)
                                <h3 class="accordion-header">
                                    <button @class(['accordion-button', 'collapsed' => !$alertInvalid]) type="button"
                                        id="home-alert-heading-{{ $alertLocale }}" data-bs-toggle="collapse"
                                        data-bs-target="#home-alert-{{ $alertLocale }}"
                                        aria-controls="home-alert-{{ $alertLocale }}"
                                        aria-expanded="{{ $alertInvalid ? 'true' : 'false' }}">
                                        <span class="d-flex flex-wrap align-items-center gap-2">
                                            <span>{{ $alertLocaleLabel }}</span>
                                            <span @class([
                                                'badge',
                                                'text-bg-success' => $alertFilled,
                                                'text-bg-secondary' => !$alertFilled,
                                            ]) data-completion-status
                                                data-filled-label="{{ __('common.translations.filled') }}"
                                                data-empty-label="{{ __('common.translations.empty') }}"
                                                aria-live="polite">{{ __($alertFilled ? 'common.translations.filled' : 'common.translations.empty') }}</span>
                                        </span>
                                    </button>
                                </h3>
                                <div @class(['accordion-collapse collapse', 'show' => $alertInvalid]) id="home-alert-{{ $alertLocale }}"
                                    aria-labelledby="home-alert-heading-{{ $alertLocale }}">
                                    <div class="accordion-body">
                            @endif
                            <label class="form-label" for="alert_{{ $alertLocale }}">
                                {{ __('admin.home.guest_alert') }} <span
                                    class="text-body-secondary">{{ __('common.labels.optional') }}</span>
                            </label>
                            <textarea @class(['form-control', 'is-invalid' => $alertInvalid]) id="alert_{{ $alertLocale }}" name="alerts[{{ $alertLocale }}]"
                                lang="{{ $alertLocale }}" rows="4" maxlength="5000" data-editor="tinymce"
                                @if ($alertInvalid) aria-invalid="true" aria-describedby="home-alert-error-{{ $alertLocale }}" @endif>{{ $alertValue }}</textarea>
                            @error('alerts.' . $alertLocale)
                                <div class="invalid-feedback" id="home-alert-error-{{ $alertLocale }}">
                                    {{ $message }}</div>
                            @enderror
                            @if ($translationsEnabled)
                        </div>
                </div>
                @endif
            </div>
            @endforeach
            </div>
            </div>
        </section>
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.home.background') }}</h2>
            </div>
            <div class="card-body">
                <p class="text-body-secondary">{{ __('admin.home.background_help') }}</p>
                <x-admin::media-picker name="background_media_id" :value="$background" profile="landscape"
                    context="home-background" upload-name="file" :label="__('admin.home.choose_image')"
                    accept="image/png,image/jpeg,image/webp" class="mb-3" />
                <div class="row g-3 mb-3">
                    <div class="col-md-12">
                        <label class="form-label" for="image_alt">{{ __('admin.slides.alt') }}</label>
                        <input class="form-control" id="image_alt" name="image_alt" maxlength="255">
                    </div>
                </div>
                <x-admin::translation-fields id="home-image-translations" prefix="image_translations" :fields="['alt' => ['label' => __('admin.slides.alt'), 'max' => 255]]" />
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
            </div>
        </section>
    </form>
@endsection
