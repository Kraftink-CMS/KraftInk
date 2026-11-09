@use('App\Kraft\SiteDates')
@props(['name', 'value' => null, 'id' => null, 'required' => false])
@php
    /** @var DateTimeInterface|string|null $value */
    $dates = app(SiteDates::class);
    $dateId = $id ?? str_replace(['.', '[', ']'], '-', $name) . '-local';
    $dateValue = old('_local_dates_values.' . $name, $dates->forInput($value));
    $dateValue = is_string($dateValue) ? $dateValue : '';
    $occurrence = old($name . '_occurrence', $dates->occurrence($value));
    try {
        $choices = $dateValue ? $dates->candidates($dateValue) : [];
    } catch (InvalidArgumentException) {
        $choices = [];
    }
@endphp
<div data-local-datetime data-date-endpoint="{{ route('site.dates.local') }}" data-zone="{{ $dates->timezone() }}"
    data-unavailable="{{ __('common.dates.unavailable') }}" data-choose="{{ __('common.dates.choose_occurrence') }}">
    <input type="hidden" name="_site_timezone" value="{{ $dates->timezone() }}">
    <input type="datetime-local" id="{{ $dateId }}" name="{{ $name }}" value="{{ $dateValue }}"
        step="1" @required($required) {{ $attributes->class(['form-control']) }}
        aria-describedby="{{ $dateId }}-zone {{ $dateId }}-status">
    <div class="form-text" id="{{ $dateId }}-zone">
        {{ __('common.dates.display_zone', ['zone' => $dates->timezone()]) }}
    </div>
    <div class="mt-2" data-date-occurrence @if (count($choices) < 2) hidden @endif>
        <label class="form-label" for="{{ $dateId }}-occurrence">{{ __('common.dates.choose_occurrence') }}</label>
        <select class="form-select" id="{{ $dateId }}-occurrence" name="{{ $name }}_occurrence"
            @required(count($choices) > 1)>
            <option value="">{{ __('common.dates.choose_occurrence') }}</option>
            @foreach ($choices as $choice)
                @if ($choice['choice'] !== 'only')
                    <option value="{{ $choice['choice'] }}" @selected($occurrence === $choice['choice'])>{{ $choice['label'] }}
                    </option>
                @endif
            @endforeach
        </select>
    </div>
    <p class="form-text mb-0" id="{{ $dateId }}-status" aria-live="polite" data-date-status></p>
</div>
