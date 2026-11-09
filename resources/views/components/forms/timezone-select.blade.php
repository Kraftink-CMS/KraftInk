@use('App\Kraft\SiteDates')
@props(['name' => 'timezone', 'value' => 'UTC', 'id' => 'site-timezone', 'detect' => false])
@php
    $selectedZone = old($name, $value);
@endphp
@php
    $selectedZone = in_array($selectedZone, SiteDates::zones(), true) ? $selectedZone : 'UTC';
@endphp
<label class="form-label" for="{{ $id }}">{{ __('common.dates.timezone') }}</label>
<select class="form-select" id="{{ $id }}" name="{{ $name }}" required @if ($detect && !old($name))
    data-detect-timezone @endif>
    @foreach (SiteDates::zones() as $zone)
        <option value="{{ $zone }}" @selected($selectedZone === $zone)>
            {{ str_replace(['_', '/'], [' ', ' / '], $zone) }}</option>
    @endforeach
</select>
<div class="form-text">
    {{ __('common.dates.timezone_help') }}
</div>
