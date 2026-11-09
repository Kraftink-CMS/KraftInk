@php
    $fieldId = 'theme-' . str_replace(['.', '[', ']'], '-', $fieldPath);
    $fieldType = $field['type'];
    $fieldValue = is_array($fieldValue) || is_scalar($fieldValue) || $fieldValue === null ? $fieldValue : null;
    $choices = $fieldType === 'media' ? [] : $configuration->choices($field, $theme);
    $describedBy = implode(
        ' ',
        array_filter([
            !empty($field['help']) ? $fieldId . '-help' : null,
            $errors->has($fieldPath) ? $fieldId . '-error' : null,
        ]),
    );
@endphp
<div class="col-12">
    @if ($fieldType === 'repeater')
        <fieldset data-theme-repeater data-max="{{ $field['max'] ?? 25 }}"
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            data-next-index="{{ is_array($fieldValue) && $fieldValue ? max(array_map('intval', array_keys($fieldValue))) + 1 : 0 }}">
            <legend class="fs-6">{{ __($field['label']) }}</legend>
            @if (!empty($field['help']))
                <p class="form-text" id="{{ $fieldId }}-help">{{ __($field['help']) }}</p>
            @endif
            <div class="vstack gap-3" data-theme-rows>
                @foreach (is_array($fieldValue) ? $fieldValue : [] as $rowKey => $rowValue)
                    @include('admin.themes.partials.row', [
                        'rowId' => $rowKey,
                        'rowValue' => is_array($rowValue) ? $rowValue : [],
                    ])
                @endforeach
            </div>
            <template data-theme-row-template>
                @include('admin.themes.partials.row', ['rowId' => '__ROW__', 'rowValue' => []])
            </template>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-3"
                data-theme-add>{{ __('themes.add_item') }}</button>
        </fieldset>
    @elseif ($fieldType === 'boolean')
        <input type="hidden" name="{{ $fieldName }}" value="0">
        <div class="form-check">
            <input @class(['form-check-input', 'is-invalid' => $errors->has($fieldPath)]) type="checkbox" id="{{ $fieldId }}" name="{{ $fieldName }}"
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                @if ($errors->has($fieldPath)) aria-invalid="true" @endif value="1"
                @checked($fieldValue)>
            <label class="form-check-label" for="{{ $fieldId }}">{{ __($field['label']) }}</label>
        </div>
    @elseif ($fieldType === 'media')
        <x-admin::media-picker :name="$fieldName" :value="is_numeric($fieldValue) ? (int) $fieldValue : null" :id="$fieldId" :error-key="$fieldPath"
            context="theme-media" profile="image" :label="__($field['label'])" :accept="implode(
                ',',
                $field['mime'] ?? ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
            )" />
    @else
        <label class="form-label" for="{{ $fieldId }}">{{ __($field['label']) }}</label>
        @if (in_array($fieldType, ['select', 'multiselect', 'menu'], true))
            @if ($fieldType === 'multiselect')
                <input type="hidden" name="{{ $fieldName }}" value="">
            @endif
            <select @class(['form-select', 'is-invalid' => $errors->has($fieldPath)]) id="{{ $fieldId }}"
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                @if ($errors->has($fieldPath)) aria-invalid="true" @endif
                name="{{ $fieldName }}{{ $fieldType === 'multiselect' ? '[]' : '' }}"
                @if ($fieldType === 'multiselect') multiple @endif @if ($field['required'] ?? false) required @endif>
                @if ($fieldType !== 'multiselect' && !array_key_exists('', $choices))
                    <option value="">{{ __('themes.none') }}</option>
                @endif
                @foreach ($choices as $choiceValue => $choiceLabel)
                    <option value="{{ $choiceValue }}" @selected($fieldType === 'multiselect' ? in_array((string) $choiceValue, array_map('strval', is_array($fieldValue) ? $fieldValue : []), true) : (string) $fieldValue === (string) $choiceValue)>
                        {{ in_array($fieldType, ['media', 'menu'], true) || !($field['translate_options'] ?? true) ? $choiceLabel : __($choiceLabel) }}
                    </option>
                @endforeach
            </select>
        @elseif (in_array($fieldType, ['textarea', 'richtext'], true))
            <textarea @class(['form-control', 'is-invalid' => $errors->has($fieldPath)]) id="{{ $fieldId }}" name="{{ $fieldName }}"
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                @if ($errors->has($fieldPath)) aria-invalid="true" @endif rows="{{ $fieldType === 'richtext' ? 6 : 3 }}"
                maxlength="{{ $field['max'] ?? 50000 }}" @if ($fieldType === 'richtext') data-editor="tinymce" @endif
                @if ($field['required'] ?? false) required @endif>{{ is_scalar($fieldValue) ? $fieldValue : '' }}</textarea>
        @else
            <input @class([
                'form-control',
                'form-control-color' => $fieldType === 'color',
                'is-invalid' => $errors->has($fieldPath),
            ]) id="{{ $fieldId }}" name="{{ $fieldName }}"
                type="{{ in_array($fieldType, ['color', 'number'], true) ? $fieldType : 'text' }}"
                value="{{ is_scalar($fieldValue) ? $fieldValue : '' }}"
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                @if ($errors->has($fieldPath)) aria-invalid="true" @endif
                @if ($fieldType === 'number') step="any" @endif
                @if (isset($field['min']) && $fieldType === 'number') min="{{ $field['min'] }}" @endif
                @if (isset($field['min']) && $fieldType !== 'number') minlength="{{ $field['min'] }}" @endif
                @if (isset($field['max']) && $fieldType === 'number') max="{{ $field['max'] }}" @endif
                @if ($fieldType !== 'number') maxlength="{{ $field['max'] ?? ($fieldType === 'url' ? 2000 : 255) }}" @endif
                @if ($field['required'] ?? false) required @endif>
        @endif
    @endif
    @if (!empty($field['help']) && $fieldType !== 'repeater')
        <div class="form-text" id="{{ $fieldId }}-help">{{ __($field['help']) }}</div>
    @endif
    @error($fieldPath)
        <div class="invalid-feedback d-block" id="{{ $fieldId }}-error">{{ $message }}</div>
    @enderror
</div>
