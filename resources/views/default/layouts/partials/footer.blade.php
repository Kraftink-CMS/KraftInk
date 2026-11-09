<footer class="site-footer border-top py-4">
    <div class="container">
        <div class="row g-4 justify-content-between">
            <div class="col-lg-6">
                <a class="footer-brand" href="{{ url('/') }}">{{ $siteName }}</a>
                <p class="footer-description text-body-secondary small mt-2 mb-0">
                    {{ $publicSite['description'] ?: __('site.home.your_server_community_and_news') }}</p>
            </div>
            <div class="col-lg-auto">
                <nav class="d-flex flex-wrap gap-3 small" aria-label="{{ __('site.home.useful_links') }}">
                    @if ($capabilities->hasCustomFooter)
                        @include('default.layouts.partials.navigation-links', [
                            'navigationItems' => $footerLinks,
                        ])
                    @else
                        @if ($capabilities->hasRules)
                            <a class="link-secondary" href="{{ url('/p/rules') }}">{{ __('common.labels.rules') }}</a>
                        @endif
                        @if ($capabilities->hasPrivacy)
                            <a class="link-secondary"
                                href="{{ url('/p/privacy') }}">{{ __('common.labels.privacy') }}</a>
                        @endif
                        @foreach ($publicSite['extensionFooterLinks'] as $navigationLink)
                            <a class="link-secondary"
                                href="{{ $navigationLink['url'] }}">{{ $navigationLink['label'] }}</a>
                        @endforeach
                    @endif
                </nav>
                <div class="mt-3 small">
                    @include('default.layouts.partials.social-links')
                </div>
            </div>
        </div>
        <div class="footer-bottom d-flex flex-wrap justify-content-between gap-2">
            <small class="text-body-secondary">{{ $publicSite['copyright'] }}</small>
            <small>
                @include('default.layouts.partials.footer-credit', [
                    'footerCreditClass' => 'link-secondary text-decoration-none',
                ])
            </small>
        </div>
    </div>
</footer>
