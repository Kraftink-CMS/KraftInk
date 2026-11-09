@props(['paginator', 'title' => null])

<section {{ $attributes->class('card') }}>
    @if ($title !== null)
        <div class="card-header">
            <h2 class="card-title">{{ $title }}</h2>
        </div>
    @endif
    <x-tables.controls :$paginator container-class="card-body" />
    <div class="card-body p-0 table-responsive">
        {{ $slot }}
    </div>
    <div class="card-footer">
        {{ $paginator->links() }}
    </div>
</section>
