<img class="kraftink-logo"
    src="{{ asset(($brandVariant ?? 'light') === 'dark' ? 'images/logos/logo-dark.svg' : 'images/logos/logo.svg') }}"
    alt="KraftInk" width="772" height="256" @if ($brandLazy ?? false) loading="lazy" @endif
    decoding="async">
