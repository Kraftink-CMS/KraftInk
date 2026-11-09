@php
    $required = in_array('required', $definition['rules'] ?? [], true);
    $help = $definition['help'] ?? null;
    $choiceTarget = $choiceTarget ?? null;
@endphp

<div class="mb-3">
    <label class="form-label" for="{{ $fieldId }}">{{ __($definition['label']) }}</label>
    @if ($definition['type'] === 'select')
        <select class="form-select" id="{{ $fieldId }}" name="transport_options[{{ $field }}]"
            @if ($originalValue !== null) data-server-transport-option data-server-original-value="{{ $originalValue }}" @endif
            @if ($help) aria-describedby="{{ $fieldId }}-help" @endif
            @if ($choiceTarget) data-fields-target="{{ $choiceTarget['id'] }}" aria-controls="{{ $choiceTarget['id'] }}" @endif
            @required($required)>
            @foreach ($definition['options'] as $option => $label)
                <option value="{{ $option }}" @selected((string) $option === (string) $value)
                    @if ($choiceTarget) data-show-fields="{{ (string) $option === (string) $choiceTarget['value'] ? '1' : '0' }}" @endif>
                    {{ __($label) }}
                </option>
            @endforeach
        </select>
    @else
        <input class="form-control" id="{{ $fieldId }}" name="transport_options[{{ $field }}]"
            type="{{ $definition['type'] }}" value="{{ $value }}"
            @if ($originalValue !== null) data-server-transport-option data-server-original-value="{{ $originalValue }}" @endif
            @if (isset($definition['min'])) min="{{ $definition['min'] }}" @endif
            @if (isset($definition['max'])) max="{{ $definition['max'] }}" @endif
            @if (isset($definition['maxlength'])) maxlength="{{ $definition['maxlength'] }}" @endif
            @if ($help) aria-describedby="{{ $fieldId }}-help" @endif
            @required($required)>
    @endif
    @if ($help)
        <div class="form-text" id="{{ $fieldId }}-help">{{ __($help) }}</div>
    @endif
</div>
