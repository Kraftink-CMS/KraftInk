@foreach ($sidebarItems as $item)
    <li @class([
        'nav-item',
        'menu-open' => isset($item['items']) && $item['active'],
    ])>
        @if (isset($item['items']))
            <a href="#" @class(['nav-link', 'active' => $item['active']]) role="button"
                aria-expanded="{{ $item['active'] ? 'true' : 'false' }}" aria-controls="admin-group-{{ $item['id'] }}">
                @include('admin.layouts.partials.sidebar.icon', ['icon' => $item['icon']])
                <p class="text-wrap text-break pe-4">{{ __($item['label']) }}<i class="nav-arrow bi bi-chevron-right"
                        aria-hidden="true"></i></p>
            </a>
            <ul class="nav nav-treeview ps-3 ms-1 border-start border-secondary border-opacity-25"
                id="admin-group-{{ $item['id'] }}">
                @include('admin.layouts.partials.sidebar.items', ['sidebarItems' => $item['items']])
            </ul>
        @else
            <a href="{{ $item['url'] }}" @class(['nav-link', 'active' => $item['active']]) @if ($item['active']) aria-current="page"
                @endif>
                @include('admin.layouts.partials.sidebar.icon', ['icon' => $item['icon']])
                <p class="text-wrap text-break">{{ __($item['label']) }}</p>
            </a>
        @endif
    </li>
@endforeach
