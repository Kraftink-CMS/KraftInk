@use('App\Kraft\Extensions\SlotRegistry')
@use('App\Kraft\Settings')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-bs-theme="light" data-lte-color-mode="off">
<head>
    <meta charset="utf-8">
    @include('partials.layout.editor-appearance')
    <meta name="kraft-media-url" content="{{ url('/admin/media') }}">
    <meta name="kraft-select-search" content="{{ __('common.currencies.search') }}">
    <meta name="kraft-select-empty" content="{{ __('common.currencies.empty') }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.forms.config')
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $adminDocumentTitle }}</title>
    @include('partials.layout.favicons')
    @vite(['resources/scss/admin.scss', 'resources/js/admin.js'])
    @stack('head')
</head>
<body class="layout-fixed sidebar-expand-lg nav-indent bg-body-tertiary">
    <div class="skip-links">
        <a class="skip-link" href="#main-content">{{ __('admin.navigation.skip') }}</a><a class="skip-link"
            href="#admin-navigation">{{ __('admin.navigation.skip_navigation') }}</a>
    </div>
    <div class="app-wrapper">
        <nav class="app-header navbar navbar-expand bg-body">
            <div class="container-fluid">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <button class="nav-link" data-lte-toggle="sidebar" type="button"
                            aria-label="{{ __('site.home.show_menu') }}" aria-controls="admin-sidebar"><i
                                class="bi bi-list" aria-hidden="true"></i></button>
                    </li>
                    <li class="nav-item d-none d-md-block"><a class="nav-link" href="{{ url('/') }}" target="_blank"><i
                                class="bi bi-box-arrow-up-right me-1"
                                aria-hidden="true"></i>{{ __('site.home.view_website') }}</a></li>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <x-extensions.slot name="admin.layout.header" />
                    @if (app(Settings::class)->get('locale.user_choice', false))
                        <li class="nav-item dropdown">
                            <button class="nav-link" data-bs-toggle="dropdown" type="button" aria-expanded="false"
                                aria-label="{{ __('admin.navigation.language') }}"><i class="bi bi-translate me-1"
                                    aria-hidden="true"></i>{{ strtoupper(app()->getLocale()) }}</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                @foreach (config('kraft.locales', []) as $localeCode => $localeName)
                                    <li><a @class([
                                        'dropdown-item',
                                        'active' => app()->getLocale() === $localeCode,
                                    ]) href="{{ url('/language/' . $localeCode) }}"
                                            lang="{{ $localeCode }}">{{ $localeName }}</a></li>
                                @endforeach
                            </ul>
                        </li>
                    @endif
                    <li class="nav-item dropdown">
                        <button class="nav-link" data-bs-toggle="dropdown" type="button" aria-expanded="false"><i
                                class="bi bi-person-circle me-1" aria-hidden="true"></i><span
                                class="d-none d-sm-inline">{{ $administrator?->username }}</span><span
                                class="visually-hidden d-sm-none">{{ __('admin.navigation.account') }}</span></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ url('/account') }}"><i class="bi bi-person me-2"
                                        aria-hidden="true"></i>{{ __('admin.navigation.account') }}</a></li>
                            <li class="d-md-none"><a class="dropdown-item" href="{{ url('/') }}" target="_blank"><i
                                        class="bi bi-box-arrow-up-right me-2"
                                        aria-hidden="true"></i>{{ __('site.home.view_website') }}</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <form data-ajax="true" data-redirect-url="response" data-success-message="false"
                                    method="post" action="{{ url('/logout') }}">
                                    @csrf
                                    <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"
                                            aria-hidden="true"></i>{{ __('admin.navigation.sign_out') }}</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>
        <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark" id="admin-sidebar">
            <div class="sidebar-brand h-auto py-3">
                <a href="{{ url('/admin') }}" class="brand-link" aria-label="KraftInk">
                    @include('partials.layout.brand', ['brandVariant' => 'dark'])
                </a>
            </div>
            <div class="sidebar-wrapper">
                <nav class="mt-2" id="navigation" aria-label="{{ __('admin.navigation.navigation') }}">
                    <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="true"
                        id="admin-navigation" tabindex="-1">
                        <li class="nav-item"><a href="{{ url('/admin') }}" @class(['nav-link', 'active' => request()->is('admin')])
                                @if (request()->is('admin')) aria-current="page" @endif><i
                                    class="nav-icon bi bi-speedometer2" aria-hidden="true"></i>
                                <p>{{ __('admin.dashboard.title') }}</p>
                            </a></li>
                        @foreach ($navigationSections as $section)
                            <li class="nav-header text-uppercase fw-semibold mt-3">{{ __($section['label']) }}</li>
                            @include('admin.layouts.partials.sidebar.items', ['sidebarItems' => $section['items']])
                        @endforeach
                    </ul>
                </nav>
            </div>
        </aside>
        <main class="app-main" id="main-content" tabindex="-1">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row align-items-center g-2">
                        <div class="col">
                            <h1 class="h3 mb-1">
                                {{ html_entity_decode($__env->yieldContent('title', __('admin.dashboard.title')), ENT_QUOTES | ENT_HTML5, 'UTF-8') }}
                            </h1>
                            @hasSection('description')
                                <p class="text-body-secondary mb-0">
                                    {{ html_entity_decode($__env->yieldContent('description'), ENT_QUOTES | ENT_HTML5, 'UTF-8') }}
                                </p>
                            @endif
                        </div>
                        @hasSection('actions')
                            <div class="col-12 col-sm-auto">
                                @yield('actions')
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="app-content">
                <div class="container-fluid pb-4">
                    <div class="vstack gap-4">
                        @include('admin.layouts.partials.update-availability')
                        @include('partials.feedback.alerts')
                        @include('admin.layouts.partials.market-announcements')
                        <x-extensions.slot name="admin.page.before" />
                        @yield('content')
                        <x-extensions.slot name="admin.page.after" />
                        @if (app(SlotRegistry::class)->diagnostics())
                            <div class="alert alert-warning mb-0" role="alert">
                                {{ __('system.extensions.slot_failed') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </main>
        <footer class="app-footer">
            <span class="float-end d-none d-sm-inline">{{ config('manifest.version') }}</span><strong>KraftInk</strong>
        </footer>
    </div>
    @stack('scripts')
</body>
</html>
