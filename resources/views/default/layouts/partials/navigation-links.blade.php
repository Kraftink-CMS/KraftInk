@foreach ($navigationItems as $navigationItem)
    @if ($navigationItem['children'])
        <details class="navigation-dropdown">
            <summary @class(['nav-link dropdown-toggle', 'active' => $navigationItem['active'] ?? false])>
                @if ($navigationItem['icon'] ?? null)
                    <i class="bi bi-{{ $navigationItem['icon'] }} me-1" aria-hidden="true"></i>
                @endif
                {{ $navigationItem['label'] }}
            </summary>
            <div class="navigation-submenu dropdown-menu">
                <a class="dropdown-item" href="{{ $navigationItem['url'] }}"
                    @if ($navigationItem['current'] ?? false) aria-current="page" @endif
                    @if ($navigationItem['new_tab']) target="_blank" rel="noopener noreferrer" @endif>{{ $navigationItem['label'] }}</a>
                @include('default.layouts.partials.navigation-links', ['navigationItems' => $navigationItem['children']])
            </div>
        </details>
    @else
        <a @class(['nav-link', 'active' => $navigationItem['active'] ?? false]) href="{{ $navigationItem['url'] }}"
            @if ($navigationItem['current'] ?? false) aria-current="page" @endif
            @if ($navigationItem['new_tab']) target="_blank" rel="noopener noreferrer" @endif>
            @if ($navigationItem['icon'] ?? null)
                <i class="bi bi-{{ $navigationItem['icon'] }} me-1" aria-hidden="true"></i>
            @endif
            {{ $navigationItem['label'] }}
        </a>
    @endif
@endforeach
