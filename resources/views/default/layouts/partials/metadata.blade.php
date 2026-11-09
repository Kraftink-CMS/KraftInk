@use('Illuminate\Support\Js')
@include('partials.forms.config')
<title>{{ $pageMetadata['title'] }}</title>
@if ($pageMetadata['description'] !== '')
    <meta name="description" content="{{ $pageMetadata['description'] }}">
@endif
@if ($pageMetadata['keywords'] !== '')
    <meta name="keywords" content="{{ $pageMetadata['keywords'] }}">
@endif
<meta name="robots" content="{{ $pageMetadata['robots'] }}">
<link rel="canonical" href="{{ $pageMetadata['canonical'] }}">
<meta property="og:site_name" content="{{ $pageMetadata['site'] }}">
<meta property="og:title" content="{{ $pageMetadata['title'] }}">
<meta property="og:description" content="{{ $pageMetadata['description'] }}">
<meta property="og:url" content="{{ $pageMetadata['canonical'] }}">
<meta property="og:type" content="{{ $pageMetadata['type'] }}">
<meta property="og:locale" content="{{ $pageMetadata['locale'] }}">
<meta name="twitter:card" content="{{ $pageMetadata['image'] ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $pageMetadata['title'] }}">
<meta name="twitter:description" content="{{ $pageMetadata['description'] }}">
@if ($pageMetadata['image'])
    <meta property="og:image" content="{{ $pageMetadata['image'] }}">
    <meta name="twitter:image" content="{{ $pageMetadata['image'] }}">
@endif
@foreach ($pageMetadata['alternates'] as $locale => $alternate)
    <link rel="alternate" hreflang="{{ $locale }}" href="{{ $alternate }}">
@endforeach
@if ($pageMetadata['structured_data'])
    <script type="application/ld+json">{!! Js::encode($pageMetadata['structured_data'], JSON_UNESCAPED_UNICODE) !!}</script>
@endif
