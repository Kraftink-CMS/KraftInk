@props(['title', 'game' => null, 'headingLevel' => 3, 'compact' => false])

<section {{ $attributes->class(['card']) }}>
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h{{ $headingLevel }} class="card-title mb-0">{{ $title }}</h{{ $headingLevel }}>
        @if ($game)
            <span class="badge text-bg-light border">{{ $game }}</span>
        @endif
    </div>
    <div @class(['card-body', 'pb-2' => $compact])>
        {{ $slot }}
    </div>
</section>
