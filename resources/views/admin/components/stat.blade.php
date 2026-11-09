@props(['label', 'value', 'icon' => 'bar-chart', 'url' => null, 'color' => 'primary', 'title' => null])
@php
    $statColor = in_array(
        $color,
        ['primary', 'secondary', 'success', 'info', 'warning', 'danger', 'light', 'dark'],
        true,
    )
        ? $color
        : 'primary';
@endphp
<div class="col-sm-6 col-xl-3">
    <div class="info-box h-100 mb-0" @if ($title) title="{{ $title }}" @endif>
        <span class="info-box-icon flex-shrink-0 text-bg-{{ $statColor }}"><i
                class="bi bi-{{ str_starts_with($icon, 'bi-') ? substr($icon, 3) : $icon }}"
                aria-hidden="true"></i></span>
        <div class="info-box-content overflow-hidden">
            <span class="info-box-text text-wrap">
                @if ($url)
                    <a class="link-body-emphasis text-decoration-none" href="{{ $url }}">{{ $label }}</a>
                @else
                    {{ $label }}
                @endif
            </span><span
                class="info-box-number fs-3">{{ is_int($value) ? number_format($value, 0, '.', str_starts_with(app()->getLocale(), 'fr') ? ' ' : ',') : $value }}</span>
        </div>
    </div>
</div>
