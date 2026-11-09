<div class="navbar-nav me-auto flex-wrap">
    @if ($showMobileMenuLabels ?? false)
        <p class="navbar-section-label d-xl-none">{{ __('site.home.main_navigation') }}</p>
    @endif
    @if ($capabilities->hasCustomHeader)
        @include('default.layouts.partials.navigation-links', [
            'navigationItems' => $headerLinks,
        ])
    @else
        <a @class(['nav-link', 'active' => request()->is('/')]) href="{{ url('/') }}">{{ __('common.labels.home') }}</a>
        @if ($capabilities->hasNews)
            <a @class(['nav-link', 'active' => request()->routeIs('site.news.*')])
                href="{{ route('site.news.index') }}">{{ __('common.labels.news_plural') }}</a>
        @endif
        @foreach ($publicSite['extensionHeaderLinks'] as $navigationLink)
            <a @class(['nav-link', 'active' => $navigationLink['active']])
                href="{{ $navigationLink['url'] }}">{{ $navigationLink['label'] }}</a>
        @endforeach
    @endif
</div>
<div class="navbar-nav gap-xl-2 align-items-xl-center">
    @if ($showMobileMenuLabels ?? false)
        <p class="navbar-section-label d-xl-none">{{ __('site.auth.account.title') }}</p>
    @endif
    @include('default.layouts.partials.appearance-menu')
    @if ($capabilities->canChooseLocale)
        <details class="navigation-dropdown">
            <summary class="nav-link dropdown-toggle">
                @if ($showMobileMenuLabels ?? false)
                    <span class="navbar-language-label d-xl-none">
                        <i class="bi bi-globe2" aria-hidden="true"></i>
                        {{ __('common.labels.language') }}
                    </span>
                @endif
                {{ strtoupper(app()->getLocale()) }}
            </summary>
            <div class="dropdown-menu dropdown-menu-end">
                @foreach (config('kraft.locales') as $languageCode => $languageName)
                    <a class="dropdown-item" href="{{ route('site.language.update', ['locale' => $languageCode]) }}"
                        lang="{{ $languageCode }}">{{ is_array($languageName) ? $languageName['name'] ?? $languageCode : $languageName }}</a>
                @endforeach
            </div>
        </details>
    @endif
    @auth
        <a class="nav-link" href="{{ route('account.notifications.index') }}"
            aria-label="{{ $publicSite['unreadNoticeCount'] ? trans_choice('site.community.unread_notices', $publicSite['unreadNoticeCount']) : __('site.community.my_notices') }}"
            title="{{ __('site.community.my_notices') }}">
            <span class="notification-icon">
                <i class="bi bi-bell" aria-hidden="true"></i>
                @if ($publicSite['unreadNoticeCount'] > 0)
                    <span class="notification-count badge rounded-pill text-bg-danger"
                        aria-hidden="true">
                        {{ $publicSite['unreadNoticeCount'] > 99 ? '99+' : $publicSite['unreadNoticeCount'] }}
                    </span>
                @endif
            </span>
            @if ($showMobileMenuLabels ?? false)
                <span class="d-xl-none">{{ __('site.community.my_notices') }}</span>
            @endif
        </a>
        <a class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-2"
            href="{{ route('account.index') }}">
            @include('partials.users.avatar', ['avatarUser' => auth()->user()])
            {{ auth()->user()->username }}</a>
    @else
        <a class="nav-link" href="{{ route('login') }}">{{ __('common.labels.login') }}</a>
        @if ($capabilities->canRegister)
            <a class="btn btn-primary btn-sm"
                href="{{ route('register') }}">{{ __('site.home.join_us') }}</a>
        @endif
    @endauth
</div>
