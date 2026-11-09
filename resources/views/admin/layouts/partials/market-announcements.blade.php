@foreach ($centralAnnouncements ?? [] as $announcement)
    <section class="alert alert-{{ $announcement['severity'] }} mb-0" role="status"
        aria-label="{{ __('market.announcements.label') }}">
        <div class="small text-body-secondary mb-1">{{ $announcement['source'] }}</div>
        <h2 class="h6 mb-2">{{ $announcement['title'] }}</h2>
        <p class="mb-0 text-break">{{ $announcement['body'] }}</p>
        @if ($announcement['url'])
            <a class="alert-link d-inline-block mt-2" href="{{ $announcement['url'] }}" target="_blank" rel="noopener noreferrer">{{ __('market.announcements.more') }}</a>
        @endif
    </section>
@endforeach
