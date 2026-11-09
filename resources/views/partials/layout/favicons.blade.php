@use('App\Kraft\Favicon')
@php
    $favicon = app(Favicon::class);
@endphp
@php
    $faviconAsset = $favicon->current();
@endphp
@if ($faviconAsset)
    <link rel="icon" type="image/png" sizes="{{ $faviconAsset->width }}x{{ $faviconAsset->height }}"
        href="{{ route('site.media.show', $faviconAsset) }}">
    <link rel="apple-touch-icon" href="{{ route('site.media.show', $faviconAsset) }}">
@else
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/favicons/favicon.svg') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/favicons/apple-touch-icon.png') }}">
@endif
<link rel="manifest" href="{{ $favicon->manifestUrl() }}">
