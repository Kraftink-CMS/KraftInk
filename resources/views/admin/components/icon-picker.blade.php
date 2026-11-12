@props(['name' => 'icon', 'value' => null, 'id' => null, 'label' => null])

@php
    $icons = app(\App\Kraft\ContentIcons::class);
    $value = is_string($value) ? $value : null;
    $icon = $icons->resolve($value);
    $library = $icons->libraryName();
    $id ??= $label ? 'icon-'.\Illuminate\Support\Str::random(12) : null;
@endphp

@pushOnce('scripts', 'content-icon-catalog')
    <script type="application/json" data-icon-catalog>@json($icons->catalog())</script>
@endPushOnce

<div data-icon-picker>
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}</label>
    @endif
    <div class="input-group">
        <span class="input-group-text" data-icon-picker-preview aria-hidden="true">
            <x-content-icon :name="$value" />
        </span>
        <input type="text" @if ($name !== null) name="{{ $name }}" @endif @if ($id !== null) id="{{ $id }}" @endif
            value="{{ $value }}" data-icon-picker-input {{ $attributes->class('form-control')->merge([
                'maxlength' => \App\Kraft\ContentIcons::MAX_NAME_LENGTH,
                'pattern' => \App\Kraft\ContentIcons::NAME_PATTERN,
                'autocomplete' => 'off',
            ])->merge($label ? [] : ['aria-label' => __('icons.label')]) }}>
        <button type="button" class="btn btn-outline-secondary" data-icon-picker-toggle aria-expanded="false"
            @disabled($attributes->get('disabled'))>{{ __('icons.choose') }}</button>
    </div>
    <div class="border rounded p-2 mt-2" data-icon-picker-panel hidden>
        <input class="form-control form-control-sm mb-2" type="search" data-icon-picker-search
            placeholder="{{ __('icons.search') }}" aria-label="{{ __('icons.search') }}" autocomplete="off">
        <ul class="list-unstyled d-grid gap-1 mb-0 icon-picker-results" data-icon-picker-results></ul>
        <p class="text-body-secondary small mb-0" data-icon-picker-empty hidden>{{ __('icons.empty') }}</p>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-icon-picker-more hidden>{{ __('icons.more') }}</button>
    </div>
    <div class="form-text">{{ __('icons.library', ['library' => $library]) }}</div>
    <div class="form-text" data-icon-picker-status aria-live="polite"
        data-unavailable="{{ __('icons.unavailable') }}" data-fallback="{{ __('icons.fallback') }}">
        @if (filled($value) && $icon === null)
            {{ __('icons.unavailable') }}
        @elseif ($icon['fallback'] ?? false)
            {{ __('icons.fallback') }}
        @endif
    </div>
</div>
