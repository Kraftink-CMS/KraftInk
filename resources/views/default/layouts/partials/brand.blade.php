@use('App\Kraft\Site\SiteIdentity')
@php
    $siteBrandAsset = app(SiteIdentity::class)->logo();
@endphp
@if ($siteBrandAsset)
    <img class="site-brand-logo" src="{{ route('site.media.show', $siteBrandAsset) }}" width="{{ $siteBrandAsset->width }}"
        height="{{ $siteBrandAsset->height }}" alt="{{ $siteName }}" decoding="async">
@elseif($siteName === 'KraftInk')
    @include('default.layouts.partials.appearance-brand')
@else
    {{ $siteName }}
@endif
