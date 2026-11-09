@extends('admin.layouts.layout')
@section('title', $instance ? __('widgets.edit') : __('widgets.add'))
@section('description', __($definition['label']))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.widgets.index') }}">{{ __('widgets.back') }}</a>
@endsection
@section('content')
    <form class="card" method="post" action="{{ route('admin.widgets.store', ['widget' => $instance['id'] ?? null]) }}"
        data-ajax="true" data-redirect-url="response">
        @csrf
        <input type="hidden" name="widget" value="{{ $key }}">
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label" for="widget-zone">{{ __('widgets.zone') }}</label>
                    <select class="form-select" id="widget-zone" name="zone" required>
                        @foreach ($zones as $id => $label)
                            <option value="{{ $id }}" @selected(old('zone', $instance['zone'] ?? array_key_first($zones)) === $id)>{{ __($label) }}</option>
                        @endforeach
                    </select>
                    @error('zone')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="widget-width">{{ __('widgets.width') }}</label>
                    <select class="form-select" id="widget-width" name="width" required>
                        @foreach (['full', 'half', 'third'] as $width)
                            <option value="{{ $width }}" @selected(old('width', $instance['width'] ?? 'half') === $width)>{{ __('widgets.widths.' . $width) }}</option>
                        @endforeach
                    </select>
                    @error('width')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
            @foreach ($definition['fields'] as $name => $field)
                @php($value = old('config.' . $name, $configuration[$name] ?? ''))
                <div class="mb-3">
                    @if ($field['type'] === 'boolean')
                        <input type="hidden" name="config[{{ $name }}]" value="0">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="widget-config-{{ $name }}" name="config[{{ $name }}]" value="1" @checked($value)>
                            <label class="form-check-label" for="widget-config-{{ $name }}">{{ __($field['label']) }}</label>
                        </div>
                    @elseif ($field['type'] === 'choice')
                        <label class="form-label" for="widget-config-{{ $name }}">{{ __($field['label']) }}</label>
                        <select class="form-select" id="widget-config-{{ $name }}" name="config[{{ $name }}]" required>
                            @foreach ($field['options'] as $option => $label)
                                <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    @else
                        <label class="form-label" for="widget-config-{{ $name }}">{{ __($field['label']) }}</label>
                        <input class="form-control" id="widget-config-{{ $name }}" name="config[{{ $name }}]" value="{{ $value }}"
                            @if ($field['type'] === 'integer') type="number" min="{{ $field['min'] ?? 1 }}" max="{{ $field['max'] ?? 100 }}" required
                            @else type="text" maxlength="{{ min(1000, $field['max'] ?? 255) }}" @endif>
                    @endif
                    @if (!empty($field['help']))<div class="form-text">{{ __($field['help']) }}</div>@endif
                    @error('config.' . $name)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            @endforeach
            <fieldset class="mb-3">
                <legend class="form-label fs-6">{{ __('widgets.languages') }}</legend>
                <div class="d-flex flex-wrap gap-3">
                    @foreach ($locales as $locale => $label)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="widget-locale-{{ $locale }}" name="locales[]" value="{{ $locale }}"
                                @checked(in_array($locale, old('locales', $instance['locales'] ?? []), true))>
                            <label class="form-check-label" for="widget-locale-{{ $locale }}">{{ is_array($label) ? ($label['name'] ?? $locale) : $label }}</label>
                        </div>
                    @endforeach
                </div>
                <div class="form-text">{{ __('widgets.languages_help') }}</div>
                @error('locales')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </fieldset>
            <input type="hidden" name="enabled" value="0">
            <div class="form-check">
                <input class="form-check-input" id="widget-enabled" type="checkbox" name="enabled" value="1" @checked(old('enabled', $instance['enabled'] ?? true))>
                <label class="form-check-label" for="widget-enabled">{{ __('widgets.enabled') }}</label>
            </div>
            @error('config')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @error('widget')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
            <a class="btn btn-outline-secondary" href="{{ route('admin.widgets.index') }}">{{ __('common.actions.cancel') }}</a>
        </div>
    </form>
@endsection
