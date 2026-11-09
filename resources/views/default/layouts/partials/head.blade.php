@use('App\Kraft\Games\GameRegistry')
<meta charset="utf-8">
@include('partials.layout.editor-appearance')
<meta name="kraft-media-url" content="{{ url('/admin/media') }}">
<meta name="viewport" content="width=device-width, initial-scale=1">
@include('default.layouts.partials.appearance-head')
<meta name="csrf-token" content="{{ csrf_token() }}">
@if (auth()->guest() && app(\App\Kraft\Auth\AuthPolicy::class)->usesModalForms())
    <meta name="kraft-auth-login" content="{{ route('login') }}">
    @if ($capabilities->canRegister)
        <meta name="kraft-auth-register" content="{{ route('register') }}">
    @endif
    @foreach ([
        'password.request', 'auth.two_factor.challenge', 'auth.registration.complete',
        'auth.registration.external.create', 'auth.games.verify', 'verification.notice',
        ...config('games.auth_steps.' . app(GameRegistry::class)->activeId(), []),
    ] as $authRoute)
        @if (Route::has($authRoute))
            <meta name="kraft-auth-step" content="{{ route($authRoute) }}">
        @endif
    @endforeach
    <meta name="kraft-auth-reset" content="{{ route('password.reset', ['token' => '__token__']) }}">
@endif
@include('default.layouts.partials.metadata')
<meta name="theme-color" content="{{ $themeColor }}">
@include('partials.layout.favicons')
@vite([$stylesheet, 'resources/js/app.js'])
