@if ($welcome['background'])
    <figure class="mb-4">
        <img src="{{ route('site.media.show', $welcome['background']) }}" class="img-fluid w-100 rounded"
            alt="{{ $welcome['background']->displayText('alt') }}" width="{{ $welcome['background']->width }}"
            height="{{ $welcome['background']->height }}">
    </figure>
@endif
@if ($welcome['alert'])
    <div class="alert alert-info alert-dismissible fade show" role="alert"
        data-welcome-alert="{{ $welcome['alert_key'] }}">
        <div class="prose">
            {!! $welcome['alert'] !!}
        </div>
        <button class="btn-close" type="button" data-bs-dismiss="alert"
            aria-label="{{ __('admin.home.dismiss') }}"></button>
    </div>
@endif
@if ($welcome['message'])
    <section class="prose mb-4" aria-label="{{ __('admin.home.welcome_message') }}">
        {!! $welcome['message'] !!}
    </section>
@endif
