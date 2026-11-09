<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-bs-theme="{{ $appearance['effective'] }}"
    data-appearance="{{ $appearance['choice'] }}">
<!--suppress HtmlRequiredTitleElement -->
<head>
    @include('default.layouts.partials.head', [
        'themeColor' => '#c04f1c',
        'stylesheet' => 'resources/scss/public.scss',
    ])
    @stack('head')
</head>
<body class="theme-native">
    <a class="visually-hidden-focusable position-absolute p-3 bg-white"
        href="#main-content">{{ __('site.home.skip_to_content') }}</a>
    @include('default.layouts.partials.header')
    <main id="main-content" class="container site-content main-content py-4">
        @include('partials.feedback.alerts')
        <x-extensions.slot name="site.page.before" />
        @yield('content')
        <x-extensions.slot name="site.page.after" />
    </main>
    @include('default.layouts.partials.footer')
    @stack('scripts')
</body>
</html>
