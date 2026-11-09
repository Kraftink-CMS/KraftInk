@use('App\Kraft\Extensions\ExtensionListing')
@use('App\Kraft\Extensions\ExtensionState')
@php
    $listing = app(ExtensionListing::class);
    $extensionPage = $type === 'game' ? $extensionPage ?? $listing->paginate($extensions, request()) : null;
    $visibleExtensions = $extensionPage ? $extensionPage->items() : $listing->filter($extensions, request());
    $listingFilters = $listing->filters(request());
    $presentations = [];
    foreach ($visibleExtensions as $extension) {
        $presentations[$extension['id']] = $listing->presentation(
            $extension,
            $selectedThemes ?? [],
            $activeGame ?? null,
            $safeMode ?? (bool) config('kraft.safe_mode'),
        );
    }
@endphp
<div id="extensions" class="vstack gap-4" @if ($extensionPage) data-ajax-pagination tabindex="-1" @endif>
    <section class="card">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get" action="{{ url()->current() }}#extensions">
                <div class="col-md-5">
                    <label class="form-label" for="extension-search">{{ __('common.actions.search') }}</label>
                    <input class="form-control" id="extension-search" name="q" type="search" maxlength="120"
                        value="{{ $listingFilters['q'] ?? '' }}"
                        placeholder="{{ __('system.extensions.search_placeholder') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="extension-state">{{ __('common.labels.status') }}</label>
                    <select class="form-select" id="extension-state" name="state">
                        <option value="">{{ __('system.extensions.all_states') }}</option>
                        @foreach (ExtensionState::listingValues($type) as $listingState)
                            <option value="{{ $listingState }}" @selected(($listingFilters['state'] ?? '') === $listingState)>
                                {{ __($type === 'game' && $listingState === 'active' ? 'site.games.module_available' : 'system.extensions.states.' . $listingState) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="extension-origin">{{ __('system.extensions.origin') }}</label>
                    <select class="form-select" id="extension-origin" name="origin">
                        <option value="">{{ __('system.extensions.all_origins') }}</option>
                        @foreach (['builtin', 'external'] as $listingOrigin)
                            <option value="{{ $listingOrigin }}" @selected(($listingFilters['origin'] ?? '') === $listingOrigin)>
                                {{ __('system.extensions.' . $listingOrigin . '_label') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <button class="btn btn-outline-secondary w-100" type="submit"
                        aria-label="{{ __('common.actions.search') }}">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </button>
                </div>
            </form>
        </div>
    </section>
    <section class="card" data-extension-list>
        <div class="card-body p-0 table-responsive">
            <table class="table align-middle mb-0">
                <thead class="text-nowrap">
                    <tr>
                        <th>{{ __('common.labels.name') }}</th>
                        @if ($type === 'theme')
                            <th>{{ __('system.extensions.theme_target') }}</th>
                        @endif
                        <th>{{ __('system.extensions.package_version') }}</th>
                        <th>{{ __('common.labels.status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($visibleExtensions as $extension)
                        @php
                            $presentation = $presentations[$extension['id']];
                            $target = $type === 'theme' && $extension['valid'] ? $extension['target'] : null;
                            $diagnostic = $extension['error'] ?? ($diagnostics[$extension['id']] ?? null);
                            if (
                                $extension['valid'] &&
                                $extension['installed'] &&
                                !$extension['manifest_current'] &&
                                !empty($extension['path'])
                            ) {
                                $diagnostic = __('system.extensions.manifest_changed');
                            }
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $extension['name'] }}</strong>
                                <small class="d-block text-body-secondary">{{ $extension['id'] }}</small>
                                @if ($diagnostic)
                                    <p class="text-body-secondary small mb-0">{{ $diagnostic }}</p>
                                @elseif (!empty($extension['description']))
                                    @php
                                        $description = null;
                                        $descriptionSafeMode =
                                            config('kraft.safe_mode') ||
                                            filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOL);
                                        if (
                                            !$descriptionSafeMode &&
                                            !empty($extension['installed']) &&
                                            !empty($extension['manifest_current']) &&
                                            ($extension['installation_state'] ?? '') !== 'quarantined'
                                        ) {
                                            $translated = __($extension['description']);
                                            if (
                                                is_string($translated) &&
                                                ($translated !== $extension['description'] ||
                                                    (!str_contains($translated, '::') &&
                                                        !preg_match(
                                                            '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_.-]+)+$/D',
                                                            $translated,
                                                        )))
                                            ) {
                                                $description = $translated;
                                            }
                                        }
                                    @endphp
                                    @if ($description)
                                        <p class="text-body-secondary small mb-0">{{ $description }}</p>
                                    @endif
                                @endif
                            </td>
                            @if ($type === 'theme')
                                <td>{{ $target ? __('system.extensions.theme_targets.' . $target) : '—' }}</td>
                            @endif
                            <td>
                                {{ $extension['version'] }}
                                @if (($extension['installed_version'] ?? null) && $extension['installed_version'] !== $extension['version'])
                                    <small
                                        class="d-block text-body-secondary">{{ __('system.extensions.recorded_version', ['version' => $extension['installed_version']]) }}</small>
                                @endif
                            </td>
                            <td>
                                <span
                                    class="badge text-bg-{{ $presentation['tone'] }}">{{ __($presentation['label']) }}</span>
                                @if ($extension['type'] === 'game' && $presentation['selected'])
                                    <span class="badge text-bg-primary">{{ __('site.games.current_game') }}</span>
                                @endif
                                @if ($extension['type'] === 'plugin' && $extension['valid'])
                                    <small class="d-block text-body-secondary mt-1">
                                        {{ __('system.extensions.supported_games') }}:
                                        {{ \App\Kraft\Extensions\GameSupport::summary($extension) }}
                                    </small>
                                @endif
                                @if (!($extension['game_compatible'] ?? true))
                                    <small class="d-block text-warning-emphasis mt-1">{{ __('system.extensions.game_incompatible') }}</small>
                                @endif
                                <small class="d-block text-body-secondary mt-1">
                                    {{ __($presentation['builtin'] ? 'system.extensions.builtin_label' : 'system.extensions.external_label') }}
                                </small>
                            </td>
                            <td class="text-end">
                                @if (auth()->user()->hasPermission('extensions.manage'))
                                    <div class="d-flex flex-wrap justify-content-end gap-2">
                                        @if ($presentation['canInstall'] || $presentation['canRepair'])
                                            <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.extensions.install') }}">
                                                @csrf
                                                <input type="hidden" name="extension_id"
                                                    value="{{ $extension['id'] }}">
                                                <button class="btn btn-primary btn-sm" type="submit">
                                                    {{ __($presentation['canRepair'] ? 'system.extensions.repair_installation' : 'common.actions.install') }}
                                                </button>
                                            </form>
                                        @endif
                                        @if (
                                            $presentation['canConfigure'] &&
                                                ($configurationRoute = $manager->configurationRoute($extension)) &&
                                                ($configurationRoute !== 'admin.themes.configuration.index' ||
                                                    auth()->user()->hasPermission('settings.manage')) &&
                                                (($configurationRoute !== 'admin.themes.configuration.index' &&
                                                    empty($extension['configuration']['permission'])) ||
                                                    auth()->user()->hasPermission($extension['configuration']['permission'] ?? 'settings.manage')))
                                            <a class="btn btn-outline-secondary btn-sm"
                                                href="{{ route($configurationRoute, $configurationRoute === 'admin.themes.configuration.index' ? ['theme' => $extension['id']] : []) }}">{{ __('system.extensions.configure') }}</a>
                                        @endif
                                        @if ($presentation['canSelect'])
                                            <form data-ajax="true" data-redirect-url="response" method="post"
                                                action="{{ route('admin.extensions.themes.select') }}">
                                                @csrf
                                                <input type="hidden" name="theme" value="{{ $extension['id'] }}">
                                                <input type="hidden" name="target" value="{{ $target }}">
                                                <button class="btn btn-outline-secondary btn-sm"
                                                    type="submit">{{ __('system.extensions.use_themes.' . $target) }}</button>
                                            </form>
                                        @endif
                                        @if ($presentation['canEnable'] || $presentation['canDisable'])
                                            <form data-ajax="true" data-redirect-url="response" method="post"
                                                action="{{ route('admin.extensions.toggle', $extension['id']) }}">
                                                @csrf
                                                <input type="hidden" name="enabled"
                                                    value="{{ $presentation['canEnable'] ? '1' : '0' }}">
                                                <button class="btn btn-outline-secondary btn-sm" type="submit">
                                                    {{ __($presentation['canEnable'] ? 'common.actions.enable' : 'common.actions.disable') }}
                                                </button>
                                            </form>
                                        @endif
                                        @if ($presentation['canRemove'])
                                            @php
                                                $removalLabel = __(
                                                    $extension['installed']
                                                        ? 'system.extensions.uninstall'
                                                        : 'system.extensions.delete_files',
                                                );
                                            @endphp
                                            <button class="btn btn-outline-danger btn-sm" type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#{{ $presentation['removalAction'] }}-{{ $extension['id'] }}"
                                                title="{{ $removalLabel }}"
                                                aria-label="{{ $removalLabel . ' · ' . $extension['name'] }}">
                                                <i class="bi {{ $extension['installed'] ? 'bi-x-circle' : 'bi-trash' }}"
                                                    aria-hidden="true"></i>
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-tables.empty :colspan="$type === 'theme' ? 5 : 4" :message="__('admin.extensions.empty')" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    @if ($extensionPage)
        {{ $extensionPage->links() }}
    @endif
    @if (auth()->user()->hasPermission('extensions.manage'))
        @foreach ($visibleExtensions as $extension)
            @if ($presentations[$extension['id']]['canRemove'])
                @php
                    $removalAction = $presentations[$extension['id']]['removalAction'];
                    $removalLabel = __(
                        $extension['installed'] ? 'system.extensions.uninstall' : 'system.extensions.delete_files',
                    );
                @endphp
                <x-forms.modal :id="$removalAction . '-' . $extension['id']" :title="$removalLabel . ' · ' . $extension['name']" :action="route('admin.extensions.' . $removalAction, $extension['id'])" :submit="$removalLabel" variant="danger">
                    <p>{{ __($extension['installed'] ? 'system.extensions.uninstall_help' : 'system.extensions.delete_files_help') }}
                    </p>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox"
                            id="{{ $removalAction }}-confirm-{{ $extension['id'] }}" name="confirm" value="1"
                            required>
                        <label class="form-check-label" for="{{ $removalAction }}-confirm-{{ $extension['id'] }}">
                            {{ __($extension['installed'] ? 'system.extensions.uninstall_confirm' : 'system.extensions.delete_files_confirm') }}
                        </label>
                    </div>
                    @include('partials.forms.confirm-action', [
                        'confirmationId' => $removalAction . '-' . $extension['id'],
                    ])
                </x-forms.modal>
            @endif
        @endforeach
    @endif
</div>
