@if ($slides->isNotEmpty())
    <section id="home-slider" class="carousel home-slider" data-kraft-slider data-bs-interval="false"
        data-bs-keyboard="true" role="region" aria-roledescription="{{ __('admin.slides.carousel') }}"
        aria-label="{{ __('admin.slides.public_label') }}" tabindex="0">
        <div class="carousel-inner">
            @foreach ($slides as $slide)
                <div @class(['carousel-item', 'active' => $loop->first]) role="group" aria-roledescription="{{ __('admin.slides.slide') }}"
                    aria-label="{{ __('admin.slides.slide_position', ['number' => $loop->iteration, 'total' => $slides->count()]) }}">
                    <div class="card border-0 mb-0">
                        <img src="{{ route('site.media.show', $slide->media) }}"
                            class="slide-image img-fluid object-fit-cover" alt="{{ $slide->alt }}"
                            width="{{ $slide->media->width }}" height="{{ $slide->media->height }}"
                            @if ($loop->first) fetchpriority="high"
                                @else loading="lazy" @endif>
                        @if ($slideControls[$slide->id]['hasCaption'])
                            <div class="slide-caption card-body">
                                <div class="slide-copy">
                                    @if ($slide->title)
                                        <h2 class="h4">{{ $slide->title }}</h2>
                                    @endif
                                    @if ($slide->description)
                                        <p class="text-break">{{ $slide->description }}</p>
                                    @endif
                                </div>
                                @if ($slideControls[$slide->id]['hasLink'])
                                    <a class="btn btn-primary" href="{{ $slide->url }}">{{ $slide->link_label }}</a>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @if ($slides->count() > 1)
            <p class="visually-hidden" data-slider-status role="status" aria-atomic="true"></p>
            <div class="slider-navigation d-flex justify-content-between align-items-center gap-3">
                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-target="#home-slider"
                    data-bs-slide="prev"><span aria-hidden="true">← </span>{{ __('admin.slides.previous') }}</button>
                <div class="carousel-indicators position-static m-0">
                    @foreach ($slides as $slide)
                        <button type="button" data-bs-target="#home-slider" data-bs-slide-to="{{ $loop->index }}"
                            @class(['active' => $loop->first]) @if ($loop->first) aria-current="true" @endif
                            aria-label="{{ __('admin.slides.go_to', ['number' => $loop->iteration]) }}"></button>
                    @endforeach
                </div>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-target="#home-slider"
                    data-bs-slide="next">{{ __('admin.slides.next') }}<span aria-hidden="true"> →</span></button>
            </div>
        @endif
    </section>
@endif
