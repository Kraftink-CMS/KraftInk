@use('App\Kraft\SiteDates')
@extends('default.layouts.layout')
@section('title', __('site.community.my_notices'))
@section('content')
    <div id="account-notices-list" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        <header class="page-heading d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div>
                <p class="small text-body-secondary mb-1">{{ __('site.auth.account.heading') }}</p>
                <h1>{{ __('site.community.my_notices') }}</h1>
                <p class="text-body-secondary mb-0">{{ __('site.community.inbox_description') }}</p>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('account.notifications.preferences.edit') }}">{{ __('notifications.preferences') }}</a>
            @if ($hasUnread)
                <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
                    action="{{ route('account.notifications.read_all') }}">
                    @csrf
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-check2-all me-1"
                            aria-hidden="true"></i>{{ __('site.community.mark_all_read') }}</button>
                </form>
            @endif
        </header>
        <div class="vstack gap-3">
            @forelse($notices as $notice)
                <article class="card">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <h2 class="h5">{{ $notice->displayText('title') }}</h2>
                            @unless ($notice->read_at)
                                <span class="badge text-bg-primary">{{ __('site.community.unread') }}</span>
                            @endunless
                        </div>
                        <time class="small text-body-secondary"
                            datetime="{{ $notice->published_at->toAtomString() }}">{{ app(SiteDates::class)->format($notice->published_at) }}</time>
                        <div class="prose my-3">
                            {!! $notice->displayText('body', true) !!}
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            @if ($notice->url)
                                <a class="btn btn-primary btn-sm"
                                    href="{{ $notice->url }}">{{ __('site.community.open_notice_link') }} <i
                                        class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                            @endif
                            @unless ($notice->read_at)
                                <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
                                    action="{{ route('account.notifications.read', $notice) }}">
                                    @csrf
                                    <button type="submit"
                                        class="btn btn-outline-secondary btn-sm">{{ __('site.community.mark_read') }}</button>
                                </form>
                            @endunless
                        </div>
                    </div>
                </article>
            @empty
                <div class="card">
                    <div class="card-body py-5 text-center">
                        <i class="bi bi-inbox fs-2 text-body-secondary" aria-hidden="true"></i>
                        <h2 class="h5 mt-3">{{ __('site.community.inbox_empty') }}</h2>
                        <p class="text-body-secondary mb-0">{{ __('site.community.inbox_empty_help') }}</p>
                    </div>
                </div>
            @endforelse
            {{ $notices->links() }}
        </div>
    </div>
@endsection
