<header class="site-header border-bottom">
    <nav class="navbar navbar-expand-xl py-3" aria-label="{{ __('site.home.main_navigation') }}">
        <div class="container header-inner gap-3">
            <a class="navbar-brand brand m-0" href="{{ url('/') }}"
                aria-label="{{ $siteName }} · {{ __('common.labels.home') }}">
                @if ($showBrandLogo ?? true)
                    @include('default.layouts.partials.brand')
                @else
                    {{ $siteName }}
                @endif
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#site-menu"
                aria-controls="site-menu" aria-expanded="false" aria-label="{{ __('common.labels.menu') }}"
                data-site-toggle hidden>
                @if ($showMobileMenuLabels ?? false)
                    <span class="navbar-toggler-label">{{ __('common.labels.menu') }}</span>
                @endif
                <span class="navbar-toggler-icon" aria-hidden="true"></span>
            </button>
            <div class="navbar-collapse" id="site-menu">
                @include('default.layouts.partials.navigation-menu')
            </div>
        </div>
    </nav>
</header>
