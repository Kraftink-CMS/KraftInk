<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('system.install.welcome_to_kraftink') }}</title>
    <meta name="theme-color" content="#c04f1c">
    @include('partials.layout.favicons')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="install-body">
    @php
        $mandatoryRequirements = collect($requirements)->filter(
            fn($requirement) => ($requirement['required'] ?? true) && empty($requirement['scope']),
        );
        $ready = !$mandatoryRequirements->contains(fn($requirement) => !$requirement['passed']);
        $passed = $mandatoryRequirements->where('passed', true)->count();
        $selectedDatabase = old('database');
        if (!is_string($selectedDatabase) || !($databaseDrivers[$selectedDatabase]['available'] ?? false)) {
            $selectedDatabase = array_find_key($databaseDrivers, fn (array $driver) => $driver['available']) ?? '';
        }
        $availableGames = $games ?? [];
        $steps = ['language', 'requirements', 'game', 'site', 'database', 'owner', 'review'];
    @endphp
    <header class="install-header border-bottom">
        <div class="install-frame container py-3 d-flex justify-content-between align-items-center gap-3">
            <a class="brand" href="{{ route('install.index', ['locale' => app()->getLocale()]) }}">
                @include('partials.layout.brand', ['brandVariant' => 'light'])
            </a>
            <h1 class="h5 mb-0">{{ __('system.install.title') }}</h1>
        </div>
    </header>
    <main class="install-frame container py-4">
        <nav aria-label="{{ __('system.install.wizard.navigation') }}">
            <ol class="install-steps list-unstyled border-bottom mb-4">
                @foreach ($steps as $index => $step)
                    <li>
                        <button type="button" data-step-target="{{ $index }}"
                            aria-controls="install-step-{{ $step }}" @if ($index === 0)
                            aria-current="step" @endif disabled><span class="install-step-number"
                                aria-hidden="true">{{ $index + 1 }}.</span><span>{{ __('system.install.wizard.steps.' . $step) }}</span></button>
                    </li>
                @endforeach
            </ol>
        </nav>
        <section class="install-main">
            <div class="install-progress visually-hidden">
                <p data-step-status aria-live="polite" aria-atomic="true">
                    {{ __('system.install.wizard.progress', ['current' => 1, 'total' => count($steps)]) }}</p>
                <progress value="1" max="{{ count($steps) }}"
                    aria-label="{{ __('system.install.wizard.navigation') }}"></progress>
            </div>
            @include('partials.feedback.alerts')
            @if ($installed ?? false)
                <div class="notice notice-success alert alert-light border alert-success">
                    {{ __('system.install.your_website_is_already_installed') }}
                </div>
                <a class="btn btn-primary" href="{{ url('/') }}">{{ __('system.install.open_my_website') }}</a>
            @else
                <form id="install-wizard" method="post" action="{{ url('/install') }}" data-install-wizard
                    data-requirements-ready="{{ $ready ? 'true' : 'false' }}"
                    data-error-fields="{{ json_encode($errors->keys()) }}"
                    data-progress-label="{{ __('system.install.wizard.progress', ['current' => ':current', 'total' => count($steps)]) }}"
                    data-password-mismatch="{{ __('system.install.wizard.password_mismatch') }}"
                    data-pending-label="{{ __('system.install.wizard.installing') }}"
                    data-empty-label="{{ __('system.install.wizard.not_provided') }}">
                    @csrf
                    <fieldset class="mb-4" id="install-step-language" data-install-step="language">
                        <legend class="float-none border-bottom pb-2 mb-3">
                            <h2 class="h5 mb-0" tabindex="-1" data-step-title>
                                {{ __('system.install.wizard.titles.language') }}</h2>
                        </legend>
                        <p class="text-body-secondary">{{ __('system.install.wizard.descriptions.language') }}</p>
                        <div class="mb-3">
                            <label class="form-label" for="locale">{{ __('system.install.primary_language') }}</label>
                            <select class="form-select" id="locale" name="locale" required data-install-language
                                data-language-url="{{ route('install.index') }}">
                                @foreach (config('kraft.locales') as $code => $label)
                                    <option value="{{ $code }}" @selected(app()->getLocale() === $code)>
                                        {{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </fieldset>
                    <fieldset class="mb-4" id="install-step-requirements" data-install-step="requirements">
                        <legend class="float-none border-bottom pb-2 mb-3">
                            <h2 class="h5 mb-0" tabindex="-1" data-step-title>
                                {{ __('system.install.wizard.titles.requirements') }}</h2>
                        </legend>
                        <p class="text-body-secondary">{{ __('system.install.wizard.descriptions.requirements') }}</p>
                        <div @class([
                            'border-bottom pb-3 mb-3 d-flex gap-2',
                            'text-success' => $ready,
                            'text-danger' => !$ready,
                        ])>
                            <span class="install-check-symbol" aria-hidden="true">{{ $ready ? '✓' : '!' }}</span>
                            <div>
                                <strong>{{ $ready ? __('system.install.wizard.requirements_ready') : __('system.install.wizard.requirements_blocked') }}</strong>
                                <p>{{ __('system.install.wizard.requirements_count', ['passed' => $passed, 'total' => $mandatoryRequirements->count()]) }}
                                </p>
                            </div>
                        </div>
                        <details class="install-check-details mb-3" @if (!$ready) open
                            @endif>
                            <summary class="fw-semibold">{{ __('system.install.wizard.requirements_details') }}</summary>
                            <div class="list-group list-group-flush mt-3">
                                @foreach ($requirements as $requirement)
                                    @php
                                        $mandatory = ($requirement['required'] ?? true) && empty($requirement['scope']);
                                    @endphp
                                    <div @class([
                                        'requirement list-group-item px-0 d-flex gap-3',
                                        'failed' => !$requirement['passed'] && $mandatory,
                                        'is-optional' => !$mandatory,
                                    ])>
                                        <span
                                            aria-hidden="true">{{ $requirement['passed'] ? '✓' : ($mandatory ? '×' : '–') }}</span>
                                        <div>
                                            <div class="install-requirement-title">
                                                <strong>{{ $requirement['name'] }}</strong>
                                                @unless ($mandatory)
                                                    <span
                                                        class="badge text-bg-light border">{{ !empty($requirement['scope']) ? __('system.install.wizard.requirement_driver', ['driver' => strtoupper($requirement['scope'])]) : __('system.install.wizard.optional') }}</span>
                                                @endunless
                                            </div>
                                            <span
                                                class="visually-hidden">{{ $requirement['passed'] ? __('system.install.wizard.check_passed') : __('system.install.wizard.check_failed') }}</span>
                                            @if (!empty($requirement['reason']))
                                                <p class="small text-body-secondary mb-1">{{ $requirement['reason'] }}
                                                </p>
                                            @endif
                                            @if (isset($requirement['detected']))
                                                <p class="small text-body-secondary mb-1">
                                                    {{ __('system.install.wizard.detected', ['value' => $requirement['detected']]) }}
                                                </p>
                                            @endif
                                            @unless ($requirement['passed'])
                                                <p>{{ $requirement['message'] }}</p>
                                                @if (!empty($requirement['docs']) && str_starts_with($requirement['docs'], 'https://'))
                                                    <a class="install-requirement-doc" href="{{ $requirement['docs'] }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer">{{ __('system.install.wizard.official_documentation') }}
                                                        ↗</a>
                                                @endif
                                            @endunless
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                        @unless ($ready)
                            <p class="form-text mb-3">{{ __('system.install.wizard.requirements_retry') }}</p>
                            <a class="btn btn-outline-secondary"
                                href="{{ route('install.index', ['locale' => app()->getLocale()]) }}">{{ __('system.install.wizard.recheck') }}</a>
                        @endunless
                    </fieldset>
                    <fieldset class="mb-4" id="install-step-game" data-install-step="game">
                        <legend class="float-none border-bottom pb-2 mb-3">
                            <h2 class="h5 mb-0" tabindex="-1" data-step-title>{{ __('system.install.wizard.titles.game') }}
                            </h2>
                        </legend>
                        <p class="text-body-secondary">{{ __('system.install.wizard.descriptions.game') }}</p>
                        <div class="list-group mb-3">
                            @forelse($availableGames as $id => $game)
                                <label
                                    class="install-game-option list-group-item list-group-item-action d-flex align-items-start gap-3 mb-0 p-3">
                                    <input class="form-check-input mt-1 flex-shrink-0" type="radio" name="game"
                                        value="{{ $id }}" required @checked(old('game') === $id)
                                        data-game-label="{{ $game['name'] }}">
                                    <span><strong class="d-block">{{ $game['name'] }}</strong>
                                        @if (!empty($game['description']))
                                            <span
                                                class="d-block text-body-secondary mt-1">{{ __($game['description']) }}</span>
                                        @endif
                                    </span>
                                </label>
                            @empty
                                <p class="text-body-secondary">{{ __('system.install.wizard.no_games') }}</p>
                            @endforelse
                        </div>
                    </fieldset>
                    <fieldset class="mb-4" id="install-step-site" data-install-step="site">
                        <legend class="float-none border-bottom pb-2 mb-3">
                            <h2 class="h5 mb-0" tabindex="-1" data-step-title>{{ __('system.install.wizard.titles.site') }}
                            </h2>
                        </legend>
                        <p class="text-body-secondary">{{ __('system.install.wizard.descriptions.site') }}</p>
                        <div class="row g-3">
                            <div class="col-12 mb-3">
                                <label class="form-label" for="site_name">{{ __('admin.settings.identity.name') }}</label>
                                <input class="form-control" id="site_name" name="site_name"
                                    value="{{ old('site_name', 'KraftInk') }}" required maxlength="120"
                                    autocomplete="organization">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label" for="url">{{ __('system.install.website_address') }}</label>
                                <input class="form-control" id="url" type="url" name="url"
                                    value="{{ old('url', url('/')) }}" required maxlength="255" autocomplete="url">
                                <small>{{ __('system.install.the_full_address_including_http_or_https') }}</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <x-forms.timezone-select :value="$timezone ?? 'UTC'" :detect="$timezone === null" />
                        </div>
                    </fieldset>
                    <fieldset class="mb-4" id="install-step-database" data-install-step="database">
                        <legend class="float-none border-bottom pb-2 mb-3">
                            <h2 class="h5 mb-0" tabindex="-1" data-step-title>
                                {{ __('system.install.wizard.titles.database') }}</h2>
                        </legend>
                        <p class="text-body-secondary">{{ __('system.install.wizard.descriptions.database') }}</p>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="database">{{ __('system.install.database_type') }}</label>
                            <select class="form-select" id="database" name="database" required>
                                @if ($selectedDatabase === '')
                                    <option value="" selected>{{ __('system.install.wizard.no_database_driver') }}
                                    </option>
                                @endif
                                @foreach ($databaseDrivers as $name => $driver)
                                    <option value="{{ $name }}" data-port="{{ $driver['port'] }}" @selected($selectedDatabase === $name)
                                        @disabled(!$driver['available'])>
                                        {{ __('system.install.wizard.'.$name.($driver['available'] ? '_option' : '_unavailable')) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <p class="form-text mb-3" data-sqlite-note>{{ __('system.install.wizard.sqlite_help') }}</p>
                        <div data-database-fields>
                            <p class="form-text mb-3">{{ __('system.install.wizard.database_help') }}</p>
                            <div class="row g-3">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="db_host">{{ __('common.labels.host') }}</label>
                                    <input class="form-control" id="db_host" name="db_host"
                                        value="{{ old('db_host', '127.0.0.1') }}" data-database-required
                                        maxlength="255" autocomplete="off">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="db_port">{{ __('common.labels.port') }}</label>
                                    <input class="form-control" id="db_port" name="db_port" type="number"
                                        value="{{ old('db_port') }}" min="1" max="65535">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"
                                        for="db_database">{{ __('system.install.database_name') }}</label>
                                    <input class="form-control" id="db_database" name="db_database"
                                        value="{{ old('db_database') }}" data-database-required maxlength="100"
                                        autocomplete="off">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"
                                        for="db_username">{{ __('system.install.database_user') }}</label>
                                    <input class="form-control" id="db_username" name="db_username"
                                        value="{{ old('db_username') }}" data-database-required maxlength="100"
                                        autocomplete="off">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label"
                                        for="db_password">{{ __('system.install.database_password') }}</label>
                                    <input class="form-control" id="db_password" type="password" name="db_password"
                                        maxlength="255" autocomplete="new-password">
                                    <small>{{ __('system.install.wizard.database_password_help') }}</small>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="mb-4" id="install-step-owner" data-install-step="owner">
                        <legend class="float-none border-bottom pb-2 mb-3">
                            <h2 class="h5 mb-0" tabindex="-1" data-step-title>
                                {{ __('system.install.wizard.titles.owner') }}</h2>
                        </legend>
                        <p class="text-body-secondary">{{ __('system.install.wizard.descriptions.owner') }}</p>
                        <div class="row g-3">
                            <div class="col-12 mb-3">
                                <label class="form-label" for="username">{{ __('site.auth.account.username') }}</label>
                                <input class="form-control" id="username" name="username"
                                    value="{{ old('username') }}" required minlength="2" maxlength="40"
                                    pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{1,39}" autocomplete="username"
                                    autocapitalize="none" spellcheck="false">
                                <div class="form-text">
                                    {{ __('site.auth.account.username_help') }}
                                </div>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label" for="email">{{ __('admin.settings.identity.email') }}</label>
                                <input class="form-control" id="email" type="email" name="email"
                                    value="{{ old('email') }}" required maxlength="255" autocomplete="email">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="password">{{ __('site.auth.password.label') }}</label>
                                <input class="form-control" id="password" type="password" name="password"
                                    minlength="12" required autocomplete="new-password">
                                <small>{{ __('system.install.wizard.password_help') }}</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"
                                    for="password_confirmation">{{ __('site.auth.password.confirm') }}</label>
                                <input class="form-control" id="password_confirmation" type="password"
                                    name="password_confirmation" minlength="12" required autocomplete="new-password">
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="mb-4" id="install-step-review" data-install-step="review">
                        <legend class="float-none border-bottom pb-2 mb-3">
                            <h2 class="h5 mb-0" tabindex="-1" data-step-title>
                                {{ __('system.install.wizard.titles.review') }}</h2>
                        </legend>
                        <p class="text-body-secondary">{{ __('system.install.wizard.descriptions.review') }}</p>
                        <dl class="install-review mb-4">
                            <div>
                                <dt>{{ __('system.install.wizard.steps.game') }}</dt>
                                <dd class="mb-0 text-break" data-summary="game">
                                    {{ __('system.install.wizard.not_provided') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('common.dates.timezone') }}</dt>
                                <dd class="mb-0 text-break" data-summary="timezone">{{ old('timezone', $timezone ?? 'UTC') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('admin.settings.identity.name') }}</dt>
                                <dd class="mb-0 text-break" data-summary="site_name">
                                    {{ old('site_name', 'KraftInk') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('system.install.website_address') }}</dt>
                                <dd class="mb-0 text-break" data-summary="url">{{ old('url', url('/')) }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('system.install.primary_language') }}</dt>
                                <dd class="mb-0 text-break" data-summary="locale">
                                    {{ __('system.install.wizard.not_provided') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('system.install.database_type') }}</dt>
                                <dd class="mb-0 text-break" data-summary="database">
                                    {{ __('system.install.wizard.not_provided') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('site.auth.account.username') }}</dt>
                                <dd class="mb-0 text-break" data-summary="username">
                                    {{ old('username', __('system.install.wizard.not_provided')) }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('admin.settings.identity.email') }}</dt>
                                <dd class="mb-0 text-break" data-summary="email">
                                    {{ old('email', __('system.install.wizard.not_provided')) }}</dd>
                            </div>
                        </dl>
                        @if ($bundledExtensions !== [])
                            <section class="mb-4" data-install-extensions>
                                <h3 class="h6">{{ __('system.install.extensions.title') }}</h3>
                                <p class="small text-body-secondary">{{ __('system.install.extensions.help') }}</p>
                                @if ($bundledPlugins !== [])
                                    <div class="list-group mb-3">
                                        @foreach ($bundledPlugins as $package)
                                            <label class="list-group-item d-flex align-items-start gap-3">
                                                <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" name="extensions[]"
                                                    value="{{ $package['id'] }}" @checked(in_array($package['id'], old('extensions', []), true)) @disabled($package['error'])
                                                    data-package-id="{{ $package['id'] }}"
                                                    data-install-requires="{{ json_encode(array_keys($package['dependencies'])) }}"
                                                    data-dependency-error="{{ __('system.install.extensions.dependencies_missing', ['name' => $package['name']]) }}">
                                                <span><span class="d-block fw-semibold">{{ $package['name'] }} <small
                                                            class="fw-normal text-body-secondary">{{ $package['version'] }}</small></span>
                                                    @if ($package['error'])
                                                        <span class="d-block small text-danger">{{ $package['error'] }}</span>
                                                    @elseif($package['dependencies'])
                                                        <span
                                                            class="d-block small text-body-secondary">{{ __('system.install.extensions.requires', ['names' => implode(', ', array_map(fn($id) => $bundledExtensions[$id]['name'] ?? $id, array_keys($package['dependencies'])))]) }}</span>
                                                    @endif
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($bundledThemes !== [])
                                    <label class="form-label" for="install-theme">{{ __('system.install.extensions.theme') }}</label>
                                    <select class="form-select mb-2" id="install-theme" name="theme" data-install-theme>
                                        <option value="">{{ __('system.extensions.native_title') }}</option>
                                        @foreach ($bundledThemes as $package)
                                            <option value="{{ $package['id'] }}" @selected(old('theme') === $package['id']) @disabled($package['error'])
                                                data-install-requires="{{ json_encode(array_keys($package['dependencies'])) }}"
                                                data-dependency-error="{{ __('system.install.extensions.dependencies_missing', ['name' => $package['name']]) }}">
                                                {{ $package['name'] }} ·
                                                {{ $package['version'] }}{{ $package['error'] ? ' · ' . $package['error'] : '' }}</option>
                                        @endforeach
                                    </select>
                                    <p class="form-text mb-0">{{ __('system.install.extensions.theme_help') }}</p>
                                @endif
                            </section>
                        @endif
                        <label class="form-check-label d-flex align-items-start gap-2">
                            <input class="form-check-input" type="checkbox" name="import_after_install"
                                value="1" @checked(old('import_after_install'))>
                            <span>{{ __('system.install.wizard.import_title') }}</span></label>
                    </fieldset>
                    <div class="install-bottom border-top pt-3 mt-4">
                        <div class="install-wizard-navigation" data-wizard-controls hidden>
                            <button class="btn btn-outline-secondary" type="button"
                                data-step-previous>{{ __('system.install.wizard.previous') }}</button>
                            <button class="btn btn-primary" type="button"
                                data-step-next>{{ __('system.install.wizard.next') }}</button>
                        </div>
                        <button class="install-submit btn btn-primary" type="submit" data-install-submit
                            @disabled(!$ready || $selectedDatabase === '')>{{ __('system.install.install_my_website') }}</button>
                    </div>
                </form>
            @endif
        </section>
    </main>
</body>
</html>
