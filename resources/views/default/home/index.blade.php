@extends('default.layouts.layout')
@section('content')
    <x-extensions.slot name="site.home.before" :context="['news' => $news]" />
    <header class="home-intro">
        <h1>{{ $publicSite['homeTitle'] ?: __('site.home.welcome_heading', ['name' => $publicSite['name']]) }}</h1>
        @if ($publicSite['homeDescription'] ?: $publicSite['description'])
            <p class="text-body-secondary mb-0">{{ $publicSite['homeDescription'] ?: $publicSite['description'] }}</p>
        @endif
    </header>
    @include('default.home.partials.slider')
    @include('default.home.partials.welcome')
    <div class="row g-4">
        @if ($capabilities->hasNews)
            <section class="col-lg-8" id="news" aria-labelledby="news-heading">
                <div class="section-heading">
                    <h2 class="h4" id="news-heading">{{ __('site.home.latest_news') }}</h2>
                    <a class="small text-decoration-none"
                        href="{{ route('site.news.index') }}">{{ __('site.home.all_news') }} <i
                            class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
                </div>
                <div id="home-news-list" data-ajax-pagination tabindex="-1" class="vstack gap-4">
                    <div class="news-feed d-grid gap-4">
                        @foreach ($news as $entry)
                            @include('default.content.partials.card')
                        @endforeach
                    </div>
                    @if (method_exists($news, 'links'))
                        <div class="mt-4">
                            {{ $news->links() }}
                        </div>
                    @endif
                </div>

            </section>
        @endif
        <aside @class([
            'col-lg-4' => $capabilities->hasNews,
            'col-12' => !$capabilities->hasNews,
        ])>
            <div class="d-grid gap-4">
                <section class="card community-card" aria-labelledby="community-heading">
                    <div class="card-body p-4">
                        <span class="community-mark" aria-hidden="true"><i class="bi bi-people"></i></span>
                        <h2 class="h5" id="community-heading">{{ __('site.home.community_title') }}</h2>
                        <p class="text-body-secondary">{{ __('site.home.community_help') }}</p>
                        @auth
                            <a class="btn btn-primary w-100"
                                href="{{ url('/account') }}">{{ __('site.auth.account.title') }}</a>
                        @else
                            @if ($capabilities->canRegister)
                                <a class="btn btn-primary w-100 mb-2"
                                    href="{{ url('/register') }}">{{ __('site.auth.register.submit') }}</a>
                            @endif
                            <a class="btn btn-outline-secondary w-100"
                                href="{{ url('/login') }}">{{ __('common.labels.login') }}</a>
                        @endauth
                    </div>
                </section>
                @include('default.home.partials.community-connection')
            </div>
        </aside>
    </div>
    <x-widgets.zone name="home" :context="['news' => $news]" />
    <x-extensions.slot name="site.home.after" :context="['news' => $news]" />
@endsection
