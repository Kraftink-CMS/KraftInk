<nav aria-label="{{ __('auth_flow.admin.heading') }}">
    <ul class="nav nav-tabs flex-wrap">
        @foreach ([
        'general' => ['admin.settings.accounts.index', 'auth_flow.admin.site_settings'],
        'oauth' => ['admin.settings.oauth.index', 'admin.integrations.oauth.title'],
    ] as $tab => [$route, $label])
            <li class="nav-item">
                <a @class(['nav-link', 'active' => $selected === $tab]) href="{{ route($route) }}"
                    @if ($selected === $tab) aria-current="page" @endif>{{ __($label) }}</a>
            </li>
        @endforeach
    </ul>
</nav>
