@use('Carbon\CarbonImmutable')
@props(['id', 'title', 'days', 'values', 'timezone'])
@php
    /** @var list<int> $values */
    /** @var list<string> $days */
    $maximum = max(1, ...$values);
    $count = count($days);
    $width = 650 / $count;
    $labels = array_map(
        fn($day) => CarbonImmutable::parse($day, $timezone)
            ->locale(app()->getLocale())
            ->translatedFormat('d M'),
        $days,
    );
@endphp
<section class="card h-100">
    <div class="card-header">
        <h3 class="card-title">{{ $title }}</h3>
        <span class="badge text-bg-light float-end">{{ number_format(array_sum($values), 0, ',', ' ') }}</span>
    </div>
    <div class="card-body">
        <svg viewBox="0 0 720 180" class="w-100 text-primary" role="img"
            aria-labelledby="{{ $id }}-title {{ $id }}-description">
            <title id="{{ $id }}-title">{{ $title }}</title>
            <desc id="{{ $id }}-description">
                {{ __('admin.statistics.chart_description', ['from' => $labels[0], 'to' => $labels[$count - 1], 'total' => array_sum($values)]) }}
            </desc>
            <path d="M40 15V165H700" fill="none" stroke="currentColor" opacity="0.3" />
            <text x="32" y="20" text-anchor="end" fill="currentColor" font-size="18">{{ $maximum }}</text>
            <text x="32" y="168" text-anchor="end" fill="currentColor" font-size="18">0</text>
            @foreach ($values as $index => $value)
                <rect x="{{ 44 + $index * $width }}" y="{{ 165 - (145 * $value) / $maximum }}"
                    width="{{ max(1, $width - 2) }}" height="{{ (145 * $value) / $maximum }}" fill="currentColor">
                    <title>{{ $labels[$index] }} : {{ $value }}</title>
                </rect>
            @endforeach
            @if (array_sum($values) === 0)
                <text x="370" y="90" text-anchor="middle" fill="currentColor"
                    font-size="22">{{ __('admin.statistics.no_events') }}</text>
            @endif
        </svg>
        <div class="d-flex justify-content-between gap-2 small text-body-secondary">
            @foreach (array_unique([0, (int) floor(($count - 1) / 2), $count - 1]) as $index)
                <span>{{ $labels[$index] }}</span>
            @endforeach
        </div>
        <details class="mt-2">
            <summary class="small text-body-secondary">{{ __('admin.statistics.show_values') }}</summary>
            <div class="table-responsive mt-2">
                <table class="table table-sm mb-0">
                    <caption class="visually-hidden">{{ $title }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('common.labels.date') }}</th>
                            <th scope="col" class="text-end">{{ __('admin.statistics.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($values as $index => $value)
                            <tr>
                                <th scope="row" class="fw-normal"><time
                                        datetime="{{ $days[$index] }}">{{ $labels[$index] }}</time></th>
                                <td class="text-end">{{ $value }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</section>
