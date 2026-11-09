@use('App\Kraft\SiteDates')
@php
    $importSources = array_keys(config('imports.adapters', []));
    $importSourceLabel = fn(string $source): string => app('translator')->has('system.imports.sources.' . $source)
        ? __('system.imports.sources.' . $source)
        : __('system.imports.source_other', ['name' => $source]);
@endphp
@if (!empty($plan))
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">{{ __('system.imports.review_title') }}</h2>
        </div>
        @if (empty($plan['errors']))
            <form method="post" action="{{ route('admin.imports.execute') }}" data-import-execute
                data-progress-url="{{ route('admin.imports.progress') }}" id="import-execute-main">
        @endif
        <div class="card-body">
            <p>{{ __('system.imports.source_cms') }} : {{ $importSourceLabel($plan['source']) }}</p>
            @foreach ($plan['errors'] ?? [] as $error)
                <div class="alert alert-danger">
                    {{ is_string($error) ? $error : json_encode($error) }}
                </div>
            @endforeach
            @foreach ($plan['warnings'] ?? [] as $warning)
                <div class="alert alert-secondary">
                    {{ is_string($warning) ? $warning : json_encode($warning) }}
                </div>
            @endforeach
            <p>{{ __('system.imports.selection_hint') }}</p>
            <p>{{ __('system.imports.preserved_accounts', ['count' => $plan['existing_accounts_preserved'] ?? 0]) }}</p>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="text-nowrap">
                        <tr>
                            <th>{{ __('system.imports.select') }}</th>
                            <th>{{ __('system.imports.source_data') }}</th>
                            <th>{{ __('system.imports.items') }}</th>
                            <th>{{ __('system.imports.processing') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plan['tables'] ?? [] as $table => $details)
                            <tr>
                                <td>
                                    @if (is_array($details) && ($details['mapped'] ?? false))
                                        <input class="form-check-input flex-shrink-0" type="checkbox"
                                            form="import-execute-main" name="tables[]" value="{{ $table }}"
                                            checked
                                            aria-label="{{ __('system.imports.select') }} {{ $table }}">
                                    @else
                                        <span aria-hidden="true">—</span>
                                    @endif
                                </td>
                                <td>{{ is_array($details) ? $details['table'] ?? $table : $table }}</td>
                                <td>{{ is_array($details) ? $details['count'] ?? 0 : $details }}</td>
                                <td>{{ is_array($details) && ($details['mapped'] ?? false) ? __('migration.converter_available') : __('system.imports.archived') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (!empty($plan['destination_counts']))
                <h3 class="h6">{{ __('system.imports.destination') }}</h3>
                <ul>
                    @foreach ($plan['destination_counts'] as $destination => $count)
                        <li>{{ $destination }} : {{ $count }}</li>
                    @endforeach
                </ul>
            @endif
            @if (!empty($plan['required_plugins']))
                <p>{{ __('system.imports.required_plugins') }} : {{ implode(', ', $plan['required_plugins']) }}</p>
            @endif
            @if (!empty($plan['extensions']['theme']))
                <p>{{ __('migration.equivalent_theme') }} : {{ $plan['extensions']['theme'] }}</p>
            @endif
            @if (!empty($plan['extensions']['unavailable']))
                <div class="alert alert-warning">
                    <p class="mb-2">{{ __('migration.unavailable_extensions') }}</p>
                    <ul class="mb-0">
                        @foreach ($plan['extensions']['unavailable'] as $extension)
                            <li>{{ $extension['name'] }} · {{ __('migration.extension_types.' . $extension['type']) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @php
                $catalogueUnavailable = collect($catalogueOutcome['unavailable'] ?? [])->reject(
                    fn($candidate) => collect($plan['extensions']['unavailable'] ?? [])->contains(
                        fn($local) => $local['name'] === $candidate['name'] && $local['type'] === $candidate['type'],
                    ),
                );
            @endphp
            @if ($catalogueUnavailable->isNotEmpty())
                <div class="alert alert-warning">
                    <p class="mb-2">{{ __('migration.unavailable_extensions') }}</p>
                    <ul class="mb-0">
                        @foreach ($catalogueUnavailable as $extension)
                            <li>{{ $extension['name'] }} · {{ __('migration.extension_types.' . $extension['type']) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (($plan['options']['mode'] ?? 'merge') === 'replace')
                <div class="alert alert-danger">
                    <strong>{{ __('system.imports.replace_warning') }}</strong>
                    <p>{{ __('system.imports.replace_preserves') }}</p>
                </div>
            @endif
            @if (empty($plan['errors']))
                @csrf
                <input type="hidden" name="hash" value="{{ $plan['hash'] }}">
                <input type="hidden" name="selection" value="1">
                <label class="form-check-label d-flex align-items-start gap-2 mb-3">
                    <input class="form-check-input flex-shrink-0" type="checkbox" name="confirmed" value="1"
                        required>
                    <span>{{ __('system.imports.confirm') }}</span></label>
                <p class="alert alert-secondary" hidden role="status" aria-live="polite" data-import-progress
                    data-starting="{{ __('system.imports.progress_starting') }}"
                    data-failed="{{ __('system.imports.interrupted') }}"
                    data-completed="{{ __('system.imports.completed') }}"
                    data-tables="{{ __('system.imports.progress_tables') }}"
                    data-rows="{{ __('system.imports.items') }}"
                    data-statussnapshotting="{{ __('system.imports.progress_snapshot') }}"
                    data-statusmapping="{{ __('system.imports.progress_mapping') }}"
                    data-statuscompleted="{{ __('system.imports.completed') }}"
                    data-statusawaitingplugins="{{ __('system.imports.progress_plugins') }}"
                    data-statusfailed="{{ __('system.imports.interrupted') }}"></p>
            @endif
        </div>
        @if (empty($plan['errors']))
            <div class="card-footer">
                <button type="submit" form="import-execute-main"
                    class="btn btn-primary">{{ __('system.imports.start') }} ↗</button>
            </div>
            </form>
        @endif
    </section>
@endif
<section class="card">
    <div class="card-header">
        <h2 class="card-title">{{ __('system.imports.source_title') }}</h2>
    </div>
    <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.imports.analyze') }}" data-import-analyze>
        <div class="card-body">
            <p class="text-secondary mb-4">{{ __('system.imports.analysis_hint') }}</p>
            <p class="form-text">{{ __('migration.database_hint') }}</p>
            @csrf
            <input type="hidden" name="installation" value="{{ $installation ?? false ? 1 : 0 }}">
            <div class="row g-3">
                <div class="mb-3 col-md-6">
                    <label class="form-label" for="source">{{ __('system.imports.source_cms') }}</label>
                    <select class="form-select" id="source" name="source" required>
                        @forelse($importSources as $sourceId)
                            <option value="{{ $sourceId }}" @selected(old('source', $plan['source'] ?? null) === $sourceId)>
                                {{ $importSourceLabel($sourceId) }}</option>
                        @empty
                            <option value="" selected disabled>{{ __('system.imports.no_sources') }}</option>
                        @endforelse
                    </select>
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label" for="driver">{{ __('system.imports.driver') }}</label>
                    <select class="form-select" id="driver" name="driver">
                        <option value="mysql" @selected(old('driver') === 'mysql')>MySQL / MariaDB</option>
                        <option value="pgsql" @selected(old('driver') === 'pgsql')>PostgreSQL</option>
                        <option value="sqlite" @selected(old('driver') === 'sqlite')>SQLite</option>
                    </select>
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label" for="host">{{ __('system.imports.host') }}</label>
                    <input class="form-control" id="host" name="host" value="{{ old('host', '127.0.0.1') }}"
                        autocomplete="off">
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label" for="port">{{ __('system.imports.port') }}</label>
                    <input class="form-control" id="port" name="port" type="number" min="1"
                        max="65535" value="{{ old('port', 3306) }}">
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label" for="source_database">{{ __('system.imports.database') }}</label>
                    <input class="form-control" id="source_database" name="database" value="{{ old('database') }}"
                        required autocomplete="off">
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label" for="username">{{ __('system.imports.username') }}</label>
                    <input class="form-control" id="username" name="username" value="{{ old('username') }}"
                        autocomplete="off">
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label" for="import_prefix">{{ __('migration.prefix') }}</label>
                    <input class="form-control" id="import_prefix" name="prefix" value="{{ old('prefix', '') }}"
                        maxlength="40" pattern="[A-Za-z0-9_]*" autocomplete="off">
                    <div class="form-text">{{ __('migration.prefix_hint') }}</div>
                </div>
                <div class="mb-3 col-12">
                    <label class="form-label"
                        for="source_password">{{ __('system.imports.source_password') }}</label>
                    <input class="form-control" id="source_password" name="source_password" type="password"
                        autocomplete="new-password">
                </div>
            </div>
            <hr>
            <div class="row g-3">
                <div class="mb-3 col-md-6">
                    <label class="form-label" for="mode">{{ __('system.imports.mode') }}</label>
                    <select class="form-select" id="mode" name="mode">
                        <option value="merge" @selected(old('mode') !== 'replace')>{{ __('system.imports.merge') }}</option>
                        <option value="replace" @selected(old('mode') === 'replace')>{{ __('system.imports.replace') }}
                        </option>
                    </select>
                    <small class="form-text">{{ __('system.imports.replace_hint') }}</small>
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label" for="existing_users">{{ __('system.imports.existing_users') }}</label>
                    <select class="form-select" id="existing_users" name="existing_users">
                        <option value="preserve" @selected(old('existing_users') !== 'reject')>{{ __('system.imports.preserve_user') }}
                        </option>
                        <option value="reject" @selected(old('existing_users') === 'reject')>{{ __('system.imports.reject_conflict') }}
                        </option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="install_plugins" value="0">
            @if (auth()->user()->hasPermission('extensions.manage'))
                <label class="form-check-label d-flex align-items-start gap-2 mb-3 mb-4">
                    <input class="form-check-input flex-shrink-0" type="checkbox" name="install_plugins"
                        value="1" @checked(old('install_plugins', true))>
                    <span>{{ __('migration.install_extensions') }}</span></label>
            @endif
            <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">{{ __('system.imports.analyze') }} ↗</button>
        </div>
    </form>
</section>
<section class="card">
    <div class="card-header">
        <h2 class="card-title">{{ __('system.imports.recent') }}</h2>
    </div>
    <div class="card-body">
        @forelse($runs ?? [] as $run)
            <article class="mb-4">
                <div class="d-flex justify-content-between gap-2">
                    <strong>{{ $importSourceLabel($run->source) }} ·
                        {{ $run->id }}</strong><span
                        class="badge text-bg-secondary">{{ __('system.imports.status.' . ($run->status ?? 'prepared')) }}</span>
                </div>
                <p class="text-secondary small">{{ app(SiteDates::class)->format($run->created_at) }}</p>
                <a class="btn btn-outline-secondary btn-sm"
                    href="{{ route('admin.imports.report', $run->id) }}">{{ __('migration.open_report') }}</a>
                @if (in_array($run->status ?? '', ['failed', 'interrupted', 'awaiting_plugins']))
                    <form method="post" action="{{ route('admin.imports.execute') }}" data-import-execute
                        data-progress-url="{{ route('admin.imports.progress') }}">
                        @csrf
                        <input type="hidden" name="resume" value="{{ $run->id }}">
                        <input type="hidden" name="confirmed" value="1">
                        <button class="btn btn-outline-secondary btn-sm"
                            type="submit">{{ __('system.imports.resume') }}</button>
                    </form>
                    <p class="alert alert-secondary" hidden role="status" aria-live="polite" data-import-progress
                        data-starting="{{ __('system.imports.progress_starting') }}"
                        data-failed="{{ __('system.imports.interrupted') }}"
                        data-completed="{{ __('system.imports.completed') }}"
                        data-tables="{{ __('system.imports.progress_tables') }}"
                        data-rows="{{ __('system.imports.items') }}"
                        data-statussnapshotting="{{ __('system.imports.progress_snapshot') }}"
                        data-statusmapping="{{ __('system.imports.progress_mapping') }}"
                        data-statuscompleted="{{ __('system.imports.completed') }}"
                        data-statusawaitingplugins="{{ __('system.imports.progress_plugins') }}"
                        data-statusfailed="{{ __('system.imports.interrupted') }}"></p>
                @endif
            </article>
        @empty
            <div class="text-center py-5 px-4 text-body-secondary">
                <h3 class="h6">{{ __('system.imports.empty') }}</h3>
                <p>{{ __('system.imports.report_hint') }}</p>
            </div>
        @endforelse
    </div>
</section>
