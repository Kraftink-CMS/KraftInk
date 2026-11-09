@php
    $fieldPrefix = $fieldPrefix ?? 'seo';
    $useOld = $useOld ?? true;
@endphp
@foreach ($seoFields as $field => $options)
    @php
        $fieldId = $fieldPrefix . '-' . $field;
        $invalid = $useOld && $errors->has($field);
        $fieldValue = $useOld ? old($field, $values[$field] ?? '') : $values[$field] ?? '';
    @endphp
    <div class="mb-3">
        <label class="form-label" for="{{ $fieldId }}">{{ $options['label'] }}</label>
        @if (($options['type'] ?? 'text') === 'textarea')
            <textarea @class(['form-control', 'is-invalid' => $invalid]) id="{{ $fieldId }}" name="{{ $field }}" rows="3"
                maxlength="{{ $options['max'] }}" data-seo-field="{{ $field }}"
                placeholder="{{ $previewDefaults[$field] ?? '' }}" aria-describedby="{{ $fieldId }}-help">{{ $fieldValue }}</textarea>
        @else
            <input @class(['form-control', 'is-invalid' => $invalid]) id="{{ $fieldId }}" name="{{ $field }}"
                value="{{ $fieldValue }}" maxlength="{{ $options['max'] }}"
                placeholder="{{ $previewDefaults[$field] ?? '' }}" data-seo-field="{{ $field }}"
                aria-describedby="{{ $fieldId }}-help">
        @endif
        @if ($invalid)
            <div class="invalid-feedback">{{ $errors->first($field) }}</div>
        @endif
        <div class="form-text" id="{{ $fieldId }}-help">{{ $options['help'] ?? __('seo.' . $field . '_help') }}
        </div>
        <div class="form-text">
            {{ __('seo.empty_uses') }} <code>{{ $previewDefaults[$field] ?? '' ?: __('seo.no_keywords') }}</code>
        </div>
    </div>
@endforeach
