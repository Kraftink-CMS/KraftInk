@php
    $settingsSections = collect($game['settings'])->groupBy(fn($field) => $field['section'] ?? '', preserveKeys: true);
@endphp
@foreach ($settingsSections as $sectionId => $fields)
    @php
        $section = $game['settings_sections'][$sectionId] ?? ['title' => 'auth_flow.admin.game_settings'];
    @endphp
    <x-admin::game-settings-card class="mb-3" :title="__($section['title'])" :game="$game['name']" :compact="true">
        @if (!empty($section['description']))
            <p class="small text-body-secondary">{{ __($section['description']) }}</p>
        @endif
        @foreach ($fields as $key => $field)
            @php
                $fieldId = 'game_' . $id . '_' . $key;
                $selectedValue = $fieldValues[$key] ?? $field['default'];
                $isSwitch =
                    count($field['options']) === 2 &&
                    isset($field['options']['enabled'], $field['options']['disabled']);
                $conditions = $field['when'] ?? [];
                $visible = collect($conditions)->every(
                    fn($expected, $dependency) => in_array(
                        $fieldValues[$dependency] ?? null,
                        (array) $expected,
                        true,
                    ),
                );
            @endphp
            @if ($conditions)
                <fieldset class="border-0 p-0 m-0"
                    data-game-field-conditions="{{ json_encode($conditions, JSON_THROW_ON_ERROR) }}"
                    @if (!$visible) hidden disabled @endif>
            @endif
            @if ($isSwitch)
                @php
                    $enabled = $field['options']['enabled'];
                    $help = $field['description'] ?? (is_array($enabled) ? $enabled['description'] ?? null : null);
                @endphp
                <div class="mb-2">
                    <input type="hidden" name="game_settings[{{ $id }}][{{ $key }}]"
                        value="disabled">
                    <div class="form-check form-switch">
                        <input class="form-check-input" id="{{ $fieldId }}" role="switch" type="checkbox"
                            name="game_settings[{{ $id }}][{{ $key }}]" value="enabled"
                            data-game-switch @checked($selectedValue === 'enabled')
                            @if ($help) aria-describedby="{{ $fieldId }}_help" @endif>
                        <label class="form-check-label"
                            for="{{ $fieldId }}">{{ __($field['label']) }}</label>
                    </div>
                    @if ($help)
                        <div class="form-text" id="{{ $fieldId }}_help">{{ __($help) }}</div>
                    @endif
                </div>
            @elseif (($field['type'] ?? 'select') === 'choice')
                <fieldset class="mb-2 border-0 p-0"
                    @if (!empty($field['description'])) aria-describedby="{{ $fieldId }}_help" @endif>
                    <legend @class([
                        'form-label fs-6',
                        'visually-hidden' => $section['title'] === $field['label'],
                    ])>
                        {{ __($field['label']) }}</legend>
                    @if (!empty($field['description']))
                        <p class="text-body-secondary small" id="{{ $fieldId }}_help">
                            {{ __($field['description']) }}</p>
                    @endif
                    <div class="row g-2">
                        @foreach ($field['options'] as $value => $option)
                            @php
                                $choice = is_array($option) ? $option : ['label' => $option];
                            @endphp
                            <div class="col-12 col-lg">
                                <input class="btn-check" type="radio"
                                    id="{{ $fieldId }}_{{ $value }}"
                                    name="game_settings[{{ $id }}][{{ $key }}]"
                                    value="{{ $value }}" autocomplete="off"
                                    aria-labelledby="{{ $fieldId }}_{{ $value }}_label"
                                    @if (!empty($choice['description'])) aria-describedby="{{ $fieldId }}_{{ $value }}_help" @endif
                                    @checked($selectedValue === $value) required>
                                <label
                                    class="btn btn-outline-primary d-flex align-items-start gap-2 text-start w-100 h-100 p-2"
                                    for="{{ $fieldId }}_{{ $value }}">
                                    @if (!empty($choice['icon']))
                                        <i class="bi {{ $choice['icon'] }} fs-5 lh-sm" aria-hidden="true"></i>
                                    @endif
                                    <span>
                                        <span class="d-block fw-semibold"
                                            id="{{ $fieldId }}_{{ $value }}_label">{{ __($choice['label']) }}</span>
                                        @if (!empty($choice['description']))
                                            <span class="d-block small fw-normal"
                                                id="{{ $fieldId }}_{{ $value }}_help">{{ __($choice['description']) }}</span>
                                        @endif
                                    </span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @else
                @php
                    $selectedOption =
                        is_string($selectedValue) || is_int($selectedValue)
                            ? $field['options'][$selectedValue] ?? null
                            : null;
                    $selectedHelp =
                        is_array($selectedOption) && array_key_exists('description', $selectedOption)
                            ? $selectedOption['description']
                            : $field['description'] ?? '';
                @endphp
                <div class="mb-2">
                    <label class="form-label" for="{{ $fieldId }}">{{ __($field['label']) }}</label>
                    <select class="form-select" id="{{ $fieldId }}"
                        name="game_settings[{{ $id }}][{{ $key }}]"
                        data-select-help="{{ $fieldId }}_help" aria-describedby="{{ $fieldId }}_help">
                        @foreach ($field['options'] as $value => $option)
                            @php
                                $optionHelp =
                                    is_array($option) && array_key_exists('description', $option)
                                        ? $option['description']
                                        : $field['description'] ?? '';
                            @endphp
                            <option value="{{ $value }}"
                                data-help="{{ $optionHelp ? __($optionHelp) : '' }}" @selected(is_scalar($selectedValue) && (string) $selectedValue === (string) $value)>
                                {{ __(is_array($option) ? $option['label'] : $option) }}</option>
                        @endforeach
                    </select>
                    <div class="form-text" id="{{ $fieldId }}_help" aria-live="polite"
                        @if (!$selectedHelp) hidden @endif>
                        {{ $selectedHelp ? __($selectedHelp) : '' }}
                    </div>
                </div>
            @endif
            @if ($conditions)
                </fieldset>
            @endif
        @endforeach
    </x-admin::game-settings-card>
@endforeach
