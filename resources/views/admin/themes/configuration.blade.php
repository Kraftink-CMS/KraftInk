@use('Illuminate\Support\Facades\Route')
@extends('admin.layouts.layout')
@section('title', __('themes.title', ['theme' => $theme['name']]))
@section('description', __('themes.description'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.extensions.themes.index') }}">{{ __('themes.back') }}</a>
@endsection
@section('content')
    <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.themes.configuration.store', ['theme' => $theme['id']]) }}" class="card">
        @csrf
        <div class="card-body vstack gap-4">
            @foreach ($sections as $section)
                <section aria-labelledby="theme-section-{{ $section['id'] }}">
                    <h2 class="h5" id="theme-section-{{ $section['id'] }}">{{ __($section['label']) }}</h2>
                    @if (!empty($section['description']))
                        <p class="text-body-secondary">{{ __($section['description']) }}</p>
                    @endif
                    <div class="row g-3">
                        @foreach ($section['fields'] as $fieldKey => $field)
                            @include('admin.themes.partials.fields', [
                                'inputRoot' => 'values',
                                'fieldPath' => 'values.' . $fieldKey,
                                'fieldName' => 'values[' . $fieldKey . ']',
                                'fieldValue' => old('values.' . $fieldKey, $values[$fieldKey] ?? null),
                            ])
                        @endforeach
                    </div>
                </section>
            @endforeach
            @if ($templateFields)
                <section aria-labelledby="theme-section-templates">
                    <h2 class="h5" id="theme-section-templates">{{ __('themes.templates') }}</h2>
                    <div class="row g-3">
                        @foreach ($templateFields as $fieldKey => $field)
                            @include('admin.themes.partials.fields', [
                                'inputRoot' => 'templates',
                                'fieldPath' => 'templates.' . $fieldKey,
                                'fieldName' => 'templates[' . $fieldKey . ']',
                                'fieldValue' => old(
                                    'templates.' . $fieldKey,
                                    $templateSelections[$fieldKey] ?? ''),
                            ])
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($contentField)
                <section aria-labelledby="theme-section-content">
                    <h2 class="h5" id="theme-section-content">{{ __('themes.content_templates') }}</h2>
                    <div class="row g-3">
                        @include('admin.themes.partials.fields', [
                            'fieldKey' => 'content_templates',
                            'field' => $contentField,
                            'inputRoot' => 'content_templates',
                            'fieldPath' => 'content_templates',
                            'fieldName' => 'content_templates',
                            'fieldValue' => old('content_templates', $contentSelections),
                        ])
                    </div>
                </section>
            @endif
            @if (!$sections && !$templateFields && !$contentField)
                <p class="text-body-secondary mb-0">{{ __('themes.empty') }}</p>
            @endif
            @if ($canPreview)
                <section>
                    <label class="form-label" for="theme-preview-path">{{ __('themes.preview_path') }}</label>
                    <input class="form-control" type="text" id="theme-preview-path" name="preview_path"
                        value="{{ $theme['target'] === 'admin' ? '/admin' : '/' }}" maxlength="500">
                    <div class="form-text">{{ __('themes.preview_help') }}</div>
                </section>
            @endif
            @if (
                !empty($theme['configuration']['route']) &&
                    Route::has($theme['configuration']['route']))
                <div>
                    <a class="btn btn-outline-secondary"
                        href="{{ route($theme['configuration']['route']) }}">{{ __('themes.advanced_configuration') }}</a>
                </div>
            @endif
        </div>
        @if ($sections || $templateFields || $contentField)
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                @if ($canPreview)
                    <button class="btn btn-outline-secondary" type="submit" formtarget="_blank"
                        formaction="{{ route('admin.themes.configuration.preview', ['theme' => $theme['id']]) }}">{{ __('themes.preview') }}</button>
                @endif
            </div>
        @endif
    </form>
@endsection
