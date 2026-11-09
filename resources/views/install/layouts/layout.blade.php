<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="kraft-editor-url" content="{{ url('/vendor/tinymce') }}">
    <meta name="kraft-media-url" content="{{ url('/admin/media') }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#12101F">
    <title>
        {{ html_entity_decode($__env->yieldContent('title', __('system.imports.installation')), ENT_QUOTES | ENT_HTML5, 'UTF-8') }}
        · KraftInk</title>
    @include('partials.layout.favicons')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="install-body install-import-body">
    <header class="site-header bg-white border-bottom">
        <div class="container d-flex justify-content-between align-items-center py-3">
            <a class="brand" href="{{ url('/') }}">
                @include('partials.layout.brand')
            </a><span class="eyebrow mb-0">{{ __('system.imports.your_installation') }}</span>
        </div>
    </header>
    <main class="container install-import-main py-4">
        @unless ($installationProtected ?? false)
            @include('partials.feedback.alerts')
        @endunless
        @yield('content')
    </main>
</body>
</html>
