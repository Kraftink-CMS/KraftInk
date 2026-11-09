@use('App\Kraft\SocialLinks')
@if ($socialLinks = app(SocialLinks::class)->visible())
    <nav class="d-flex flex-wrap gap-3" aria-label="{{ __('admin.social.title') }}">
        @foreach ($socialLinks as $socialLink)
            <a class="d-inline-flex align-items-center gap-2 text-decoration-none" href="{{ $socialLink['url'] }}"
                target="_blank" rel="noopener noreferrer me" title="{{ $socialLink['title'] }}"><i
                    class="bi bi-{{ $socialLink['icon'] }}" style="color: {{ $socialLink['color'] }}"
                    aria-hidden="true"></i><span>{{ $socialLink['title'] }}</span><span
                    class="visually-hidden">{{ __('admin.social.new_tab') }}</span></a>
        @endforeach
    </nav>
@endif
