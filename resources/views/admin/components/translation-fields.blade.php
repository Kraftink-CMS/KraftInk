@use('App\Kraft\LocalePolicy')
@use('App\Kraft\TextTranslations')
@props(['id', 'fields', 'values' => [], 'prefix' => 'translations', 'useOld' => true])
@if (app(LocalePolicy::class)->allowsChoice())
    <div class="my-3">
        <h3 class="h6">{{ __('common.translations.title') }}</h3>
        <p class="text-body-secondary">{{ __('common.translations.help') }}</p>
        <div class="accordion" id="{{ $id }}">
            @foreach (config('kraft.locales') as $locale => $language)
                @continue($locale === app(LocalePolicy::class)->defaultLocale())
                @php
                    $entry =
                        ($useOld ? old($prefix . '.' . $locale, $values[$locale] ?? []) : $values[$locale] ?? []) ?? [];
                    $entry = is_array($entry) ? $entry : [];
                    $invalid = $useOld && $errors->has($prefix . '.' . $locale . '*');
                    $filled = collect($fields)->contains(
                        fn($options, $field) => app(TextTranslations::class)->filled(
                            is_string($entry[$field] ?? null) ? $entry[$field] : '',
                            ($options['type'] ?? 'text') === 'richtext',
                        ),
                    );
                @endphp
                <div class="accordion-item" data-completion-group>
                    <h4 class="accordion-header">
                        <button @class(['accordion-button', 'collapsed' => !$invalid]) type="button"
                            id="{{ $id }}-heading-{{ $locale }}" data-bs-toggle="collapse"
                            data-bs-target="#{{ $id }}-panel-{{ $locale }}"
                            aria-controls="{{ $id }}-panel-{{ $locale }}"
                            aria-expanded="{{ $invalid ? 'true' : 'false' }}">
                            <span class="d-flex flex-wrap align-items-center gap-2">
                                <span>{{ $language }}</span>
                                <span @class([
                                    'badge',
                                    'text-bg-success' => $filled,
                                    'text-bg-secondary' => !$filled,
                                ]) data-completion-status
                                    data-filled-label="{{ __('common.translations.filled') }}"
                                    data-empty-label="{{ __('common.translations.empty') }}"
                                    aria-live="polite">{{ __($filled ? 'common.translations.filled' : 'common.translations.empty') }}</span>
                            </span>
                        </button>
                    </h4>
                    <div @class(['accordion-collapse collapse', 'show' => $invalid]) id="{{ $id }}-panel-{{ $locale }}"
                        aria-labelledby="{{ $id }}-heading-{{ $locale }}">
                        <div class="accordion-body">
                            @foreach ($fields as $field => $options)
                                @php
                                    $fieldId = $id . '-' . $locale . '-' . $field;
                                    $fieldError = $prefix . '.' . $locale . '.' . $field;
                                    $fieldValue = is_string($entry[$field] ?? null) ? $entry[$field] : '';
                                    $fieldInvalid = $useOld && $errors->has($fieldError);
                                @endphp
                                <div @class(['mb-3' => !$loop->last])>
                                    <label class="form-label" for="{{ $fieldId }}">
                                        {{ $options['label'] }} <span
                                            class="text-body-secondary">{{ __('common.labels.optional') }}</span>
                                    </label>
                                    @if (in_array($options['type'] ?? 'text', ['textarea', 'richtext']))
                                        <textarea @class(['form-control', 'is-invalid' => $fieldInvalid]) id="{{ $fieldId }}"
                                            name="{{ $prefix }}[{{ $locale }}][{{ $field }}]" lang="{{ $locale }}"
                                            maxlength="{{ $options['max'] ?? 255 }}" rows="{{ $options['rows'] ?? 4 }}"
                                            @if (($options['type'] ?? 'text') === 'richtext') data-editor="tinymce" @endif
                                            @if ($fieldInvalid) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif>{{ $fieldValue }}</textarea>
                                    @else
                                        <input @class(['form-control', 'is-invalid' => $fieldInvalid]) id="{{ $fieldId }}"
                                            name="{{ $prefix }}[{{ $locale }}][{{ $field }}]"
                                            value="{{ $fieldValue }}" lang="{{ $locale }}"
                                            maxlength="{{ $options['max'] ?? 255 }}"
                                            @if ($fieldInvalid) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif>
                                    @endif
                                    @if ($fieldInvalid)
                                        <div class="invalid-feedback" id="{{ $fieldId }}-error">
                                            {{ $errors->first($fieldError) }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
