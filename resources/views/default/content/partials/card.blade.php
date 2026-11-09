@use('App\Kraft\SiteDates')
@use('Illuminate\Support\Str')
@use('App\Models\Content\ContentEntry')
<article class="card content-card news-card h-100 overflow-hidden">
    @php
        /** @var ContentEntry $entry */
        $cover = $entry->coverAsset();
    @endphp
    @if ($cover)
        <a class="news-cover-link" href="{{ $entry->url() }}" tabindex="-1" aria-hidden="true"><img
                class="card-img-top news-cover" src="{{ $entry->coverUrl() }}"
                alt="{{ $entry->cover_image_id ? $entry->title : ($cover->displayText('alt') ?: $entry->title) }}" width="{{ $cover->width }}"
                height="{{ $cover->height }}" loading="lazy"></a>
    @endif
    <div class="card-body p-4 d-flex flex-column align-items-start">
        <div class="small text-body-secondary mb-2">
            @if ($entry->is_pinned)
                <span class="badge text-bg-secondary me-2">{{ __('site.content.pinned') }}</span>
            @endif
            <time
                datetime="{{ $entry->published_at?->toAtomString() }}">{{ app(SiteDates::class)->format($entry->published_at, 'd F Y') }}</time>
        </div>
        <h2 class="h5"><a class="text-decoration-none" href="{{ $entry->url() }}">{{ $entry->title }}</a></h2>
        <p class="text-body-secondary">
            {{ $entry->summary ?: Str::limit(strip_tags($entry->body), 220) }}</p>
        <small
            class="text-body-secondary mb-3">{{ __('site.content.by_author', ['name' => $entry->author?->username ?? __('site.content.former_member')]) }}</small>
        <a class="link-primary text-decoration-none fw-semibold mt-auto"
            href="{{ $entry->url() }}">{{ __('site.content.read') }} <i class="bi bi-arrow-right ms-1"
                aria-hidden="true"></i></a>
    </div>
</article>
