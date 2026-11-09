@if (is_array($icon))
    <img class="nav-icon object-fit-contain"
        src="{{ route('site.extensions.assets.show', ['id' => $icon['extension'], 'asset' => $icon['asset']]) }}" alt=""
        width="18" height="18">
@else
    <i class="nav-icon bi bi-{{ str_starts_with($icon, 'bi-') ? substr($icon, 3) : $icon }}" aria-hidden="true"></i>
@endif
