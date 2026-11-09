@extends('default.layouts.layout')
@section('title', __('site.content.news'))
@section('description', __('site.content.news_help'))
@section('content')
    <div id="site-news-list" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        <header class="page-heading">
            <h1 class="h2">{{ __('site.content.news') }}</h1>
            <p class="text-body-secondary">{{ __('site.content.news_help') }}</p>
        </header>
        <form class="row g-2 align-items-end mb-4" method="get" action="{{ route('site.news.index') }}">
            <div class="col-sm-8 col-lg-5">
                <label class="form-label" for="news-search">{{ __('site.content.search') }}</label>
                <input class="form-control" id="news-search" type="search" name="q" maxlength="100"
                    value="{{ $search }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary" type="submit">{{ __('site.content.search_button') }}</button>
            </div>
        </form>
        <div class="row g-4 mb-4">
            @forelse($news as $entry)
                <div class="col-md-6 col-xl-4">
                    @include('default.content.partials.card')
                </div>
            @empty
                <div class="col-12">
                    <p class="text-body-secondary">{{ $search ? __('site.content.search_empty') : __('site.content.no_news') }}
                    </p>
                </div>
            @endforelse
        </div>
        {{ $news->links() }}
    </div>
@endsection
