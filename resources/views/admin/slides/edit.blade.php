@use('App\Kraft\Slider')
@extends('admin.layouts.layout')
@section('title', $slide ? __('admin.slides.edit') : __('admin.slides.create'))
@section('description', __('admin.slides.description'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.slides.index') }}">
        {{ __('admin.slides.back') }}</a>
@endsection
@section('content')
    <form data-ajax="true" data-redirect-on-upload="true"
        @unless ($slide) data-redirect-url="response" @endunless method="post"
        action="{{ route('admin.slides.store', $slide) }}" enctype="multipart/form-data">
        @csrf
        <div class="row g-4">
            <div class="col-lg-8 d-flex flex-column gap-4">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('admin.slides.image') }}</h2>
                    </div>
                    <div class="card-body">
                        <x-admin::media-picker name="media_asset_id" :value="$selectedAsset" profile="landscape" context="slide"
                            upload-name="file" :label="__('admin.slides.choose_existing')" :help="__('admin.slides.image_help', [
                                'size' => number_format(config('community.media_max_kilobytes') / 1024, 0),
                            ])"
                            accept="image/png,image/jpeg,image/webp" />
                        <div class="mb-0 mt-3">
                            <label class="form-label" for="alt">{{ __('admin.slides.alt') }}</label>
                            <input class="form-control" id="alt" name="alt" value="{{ old('alt', $slide?->alt) }}"
                                required maxlength="255">
                            <div class="form-text">
                                {{ __('admin.slides.alt_help') }}
                            </div>
                        </div>
                    </div>
                </section>
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('common.labels.content') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="title">{{ __('common.labels.title') }}</label>
                            <input class="form-control" id="title" name="title"
                                value="{{ old('title', $slide?->title) }}" maxlength="160">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="description">{{ __('common.labels.description') }}</label>
                            <textarea class="form-control" id="description" name="description" rows="3" maxlength="2000">{{ old('description', $slide?->description) }}</textarea>
                            <div class="form-text">
                                {{ __('admin.slides.text_help') }}
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="url">{{ __('admin.slides.link_url') }}</label>
                                <input class="form-control" id="url" name="url"
                                    value="{{ old('url', $slide?->url) }}" maxlength="1000"
                                    aria-describedby="slide-url-help">
                                <div class="form-text" id="slide-url-help">
                                    {{ __('admin.slides.link_help') }}
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="link_label">{{ __('admin.slides.link_label') }}</label>
                                <input class="form-control" id="link_label" name="link_label"
                                    value="{{ old('link_label', $slide?->link_label) }}" maxlength="100">
                            </div>
                        </div>
                        <x-admin::translation-fields id="slide-translations" :fields="[
                            'title' =>
                                ['label' => __('common.labels.title')] + Slider::TRANSLATION_FIELDS['title'],
                            'description' =>
                                ['label' => __('common.labels.description'), 'type' => 'textarea', 'rows' => 3] +
                                Slider::TRANSLATION_FIELDS['description'],
                            'alt' => ['label' => __('admin.slides.alt')] + Slider::TRANSLATION_FIELDS['alt'],
                            'link_label' =>
                                ['label' => __('admin.slides.link_label')] +
                                Slider::TRANSLATION_FIELDS['link_label'],
                        ]" :values="$slide?->translations ?? []" />
                    </div>
                </section>
            </div>
            <aside class="col-lg-4">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('common.labels.publication') }}</h2>
                    </div>
                    <div class="card-body">
                        <input type="hidden" name="enabled" value="0">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" id="enabled" type="checkbox" name="enabled" value="1"
                                @checked(old('enabled', $slide?->enabled ?? true))>
                            <label class="form-check-label" for="enabled">{{ __('admin.slides.enabled') }}</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="locale">{{ __('common.labels.language') }}</label>
                            <select class="form-select" id="locale" name="locale">
                                <option value="">{{ __('admin.slides.all_languages') }}</option>
                                @foreach (config('kraft.locales') as $code => $label)
                                    <option value="{{ $code }}" @selected(old('locale', $slide?->locale) === $code)>{{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="position">{{ __('admin.slides.order') }}</label>
                            <input class="form-control" id="position" type="number" name="position" min="0"
                                max="9999" value="{{ old('position', $slide?->position ?? 0) }}" required>
                            <div class="form-text">
                                {{ __('admin.slides.order_help') }}
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="starts_at">{{ __('admin.slides.starts_at') }}</label>
                            <x-forms.local-datetime name="starts_at" id="starts_at" :value="$slide?->starts_at" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ends_at">{{ __('admin.slides.ends_at') }}</label>
                            <x-forms.local-datetime name="ends_at" id="ends_at" :value="$slide?->ends_at" />
                        </div>
                        <p class="form-text mb-0">
                            {{ __('admin.slides.dates_help', ['timezone' => config('app.timezone')]) }}
                        </p>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                        <a class="btn btn-outline-secondary"
                            href="{{ route('admin.slides.index') }}">{{ __('common.actions.cancel') }}</a>
                    </div>
                </section>
            </aside>
        </div>
    </form>
@endsection
