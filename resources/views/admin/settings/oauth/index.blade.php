@use('App\Kraft\Games\GameRegistry')
@extends('admin.layouts.layout')
@section('title', __('auth_flow.admin.heading'))
@section('description', __('admin.integrations.oauth.description'))
@section('actions')
    <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#oauth-add">
        {{ __('admin.integrations.oauth.additional') }}</button>
@endsection
@section('content')
    @include('admin.settings.accounts.partials.navigation', ['selected' => 'oauth'])
    <section class="card">
        <div class="card-header p-0">
            <ul class="nav nav-tabs card-header-tabs m-0" role="tablist">
                @foreach (['services', 'recoveries'] as $tab)
                    <li class="nav-item" role="presentation">
                        <button @class(['nav-link', 'active' => $tab === 'services']) id="oauth-tab-{{ $tab }}" data-bs-toggle="tab"
                            data-bs-target="#oauth-{{ $tab }}" type="button" role="tab"
                            aria-controls="oauth-{{ $tab }}"
                            aria-selected="{{ $tab === 'services' ? 'true' : 'false' }}">{{ __('admin.integrations.oauth.' . $tab) }}
                            @if ($tab === 'recoveries' && $recoveryCount)
                                <span class="badge text-bg-secondary ms-1">{{ $recoveryCount }}</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="oauth-services" role="tabpanel" aria-labelledby="oauth-tab-services">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 border-bottom pb-3 mb-3">
                        <div>
                            <h2 class="h5 mb-1">{{ __('admin.integrations.oauth.global') }}</h2>
                            <p class="text-body-secondary mb-0">
                                {{ $registry->globallyEnabled() ? __('admin.integrations.oauth.global_on') : __('admin.integrations.oauth.global_off') }}
                            </p>
                        </div>
                        <form data-ajax="true" data-redirect-url="response" method="post"
                            action="{{ route('admin.settings.oauth.preview') }}">
                            @csrf
                            <input type="hidden" name="operation" value="global">
                            <input type="hidden" name="enabled" value="{{ $registry->globallyEnabled() ? 0 : 1 }}">
                            <button class="btn btn-outline-primary"
                                type="submit">{{ $registry->globallyEnabled() ? __('admin.integrations.oauth.pause_all') : __('admin.integrations.oauth.enable_all') }}</button>
                        </form>
                    </div>
                    @if (($settings['auth.mode'] ?? 'mixed') === 'local')
                        <p class="alert alert-secondary">{{ __('admin.integrations.oauth.local_mode_help') }}</p>
                    @endif
                    <div id="admin-oauth-providers" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
                        <x-tables.controls :paginator="$providers" />
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th data-table-sort="name">{{ __('admin.integrations.oauth.provider_column') }}
                                        </th>
                                        <th>{{ __('common.labels.status') }}</th>
                                        <th>{{ __('admin.integrations.oauth.linked_column') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($providers as $provider)
                                        @php
                                            $id = $provider['id'];
                                            $metadata = $registry->metadata($id);
                                            $game = app(GameRegistry::class)->find($metadata['game'] ?? null);
                                        @endphp
                                        @php
                                            $serviceEnabled = (bool) ($settings['oauth.' . $id . '.enabled'] ?? false);
                                        @endphp
                                        <tr>
                                            <td><strong>
                                                    @if (!empty($metadata['icon']))
                                                        <i class="bi bi-{{ $metadata['icon'] }} me-2"
                                                            aria-hidden="true"></i>
                                                    @endif
                                                    {{ $metadata['name'] }}
                                                </strong>
                                                @if ($game)
                                                    <span
                                                        class="badge text-bg-light border ms-1">{{ $game->metadata()['name'] }}</span>
                                                @endif
                                            </td>
                                            <td><span
                                                    @class([
                                                        'badge',
                                                        'text-bg-success' =>
                                                            $serviceEnabled &&
                                                            $registry->globallyEnabled() &&
                                                            $registry->configured($id),
                                                        'text-bg-secondary' =>
                                                            !$serviceEnabled ||
                                                            !$registry->globallyEnabled() ||
                                                            !$registry->configured($id),
                                                    ])>{{ !$registry->configured($id) ? __('admin.integrations.oauth.not_configured') : ($serviceEnabled ? ($registry->globallyEnabled() ? __('common.statuses.active') : __('admin.integrations.oauth.status_paused')) : __('common.statuses.inactive')) }}</span>
                                            </td>
                                            <td>{{ $linkedCounts[$id] ?? 0 }}</td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle"
                                                        type="button" data-bs-toggle="dropdown"
                                                        aria-expanded="false">{{ __('common.labels.actions') }}</button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            <button class="dropdown-item" type="button"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#oauth-config-{{ $id }}">{{ __('admin.integrations.oauth.configure') }}</button>
                                                        </li>
                                                        @if ($serviceEnabled)
                                                            <li>
                                                                <form data-ajax="true" data-redirect-url="response"
                                                                    method="post"
                                                                    action="{{ route('admin.settings.oauth.preview') }}">
                                                                    @csrf
                                                                    <input type="hidden" name="operation" value="provider">
                                                                    <input type="hidden" name="provider"
                                                                        value="{{ $id }}">
                                                                    <button class="dropdown-item text-danger"
                                                                        type="submit">{{ __('admin.integrations.oauth.deactivate') }}</button>
                                                                </form>
                                                            </li>
                                                        @endif
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $providers->links() }}
                        @foreach ($providers as $provider)
                            @php
                                $id = $provider['id'];
                                $metadata = $registry->metadata($id);
                            @endphp
                            @php
                                $configValue = fn($key, $fallback = '') => old('provider') === $id
                                    ? old($key, $fallback)
                                    : $fallback;
                            @endphp
                            <x-forms.modal :id="'oauth-config-' . $id" :title="$metadata['name']" :action="route('admin.settings.oauth.update')" size="xl"
                                data-oauth-provider-form>
                                <input type="hidden" name="provider" value="{{ $id }}">
                                <div class="mb-4">
                                    <h3 class="h6">{{ __('admin.integrations.oauth.setup_application') }}</h3>
                                    @if (!empty($metadata['help']))
                                        <p>{{ __($metadata['help'] . '.description') }}</p>
                                    @endif
                                    <div class="d-flex flex-wrap gap-2">
                                        @if (!empty($metadata['console']))
                                            <a class="btn btn-outline-primary btn-sm" href="{{ $metadata['console'] }}"
                                                target="_blank"
                                                rel="noopener">{{ __('admin.integrations.oauth.open_console') }}</a>
                                        @endif
                                        @if (!empty($metadata['documentation']))
                                            <a class="btn btn-outline-secondary btn-sm"
                                                href="{{ $metadata['documentation'] }}" target="_blank"
                                                rel="noopener">{{ __('admin.integrations.oauth.documentation') }}</a>
                                        @endif
                                    </div>
                                </div>
                                <div class="border-top pt-3 mb-4">
                                    <h3 class="h6">{{ __('admin.integrations.oauth.setup_callback') }}</h3>
                                    <label class="form-label"
                                        for="callback_{{ $id }}">{{ __('admin.integrations.oauth.callback') }}</label>
                                    <div class="input-group">
                                        <input class="form-control" id="callback_{{ $id }}"
                                            value="{{ route('auth.oauth.callback', $id) }}" readonly>
                                        <button class="btn btn-outline-secondary" type="button"
                                            data-copy="{{ route('auth.oauth.callback', $id) }}"
                                            data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
                                    </div>
                                    <div class="form-text">
                                        {{ __('admin.integrations.oauth.callback_help') }}
                                    </div>
                                </div>
                                <div class="border-top pt-3 mb-4">
                                    <h3 class="h6">{{ __('admin.integrations.oauth.setup_credentials') }}</h3>
                                    <div class="row g-3">
                                        @if ($metadata['credentials']['client_id'] ?? true)
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label"
                                                    for="client_{{ $id }}">{{ __('admin.integrations.oauth.credentials.client_id') }}</label>
                                                <input class="form-control" id="client_{{ $id }}"
                                                    name="client_id" data-oauth-client
                                                    value="{{ $configValue('client_id', $settings['oauth.' . $id . '.client_id'] ?? '') }}"
                                                    autocomplete="off" maxlength="255">
                                            </div>
                                        @else
                                            <input type="hidden" name="client_id" value="">
                                        @endif
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label"
                                                for="secret_{{ $id }}">{{ __($metadata['secret_label'] ?? 'admin.integrations.oauth.credentials.client_secret') }}</label>
                                            <input class="form-control" id="secret_{{ $id }}" type="password"
                                                name="client_secret" data-oauth-secret
                                                data-oauth-required="{{ $metadata['credentials']['client_secret'] ?? true ? 1 : 0 }}"
                                                data-has-secret="{{ $registry->hasSavedSecret($id) ? 1 : 0 }}"
                                                autocomplete="new-password" maxlength="2000">
                                            <div class="form-text">
                                                {{ $registry->hasSavedSecret($id) ? __('admin.integrations.oauth.credentials.secret_help') : __('admin.integrations.oauth.new_secret_help') }}
                                            </div>
                                        </div>
                                    </div>
                                    @foreach ($metadata['fields'] ?? [] as $key => $field)
                                        <div class="mb-3">
                                            <label class="form-label"
                                                for="extra_{{ $id }}_{{ $key }}">{{ __($field['label']) }}</label>
                                            <input class="form-control"
                                                id="extra_{{ $id }}_{{ $key }}"
                                                name="{{ $key }}"
                                                value="{{ $configValue($key, $settings['oauth.' . $id . '.' . $key] ?? ($field['default'] ?? '')) }}"
                                                maxlength="255" @required(in_array('required', $field['rules'] ?? [], true))>
                                            @if (!empty($field['help']))
                                                <div class="form-text">
                                                    {{ __($field['help']) }}
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if (!empty($metadata['help']))
                                        <p class="form-text">{{ __($metadata['help'] . '.note') }}</p>
                                    @endif
                                    @if ($settings['oauth.' . $id . '.enabled'] ?? false)
                                        <input type="hidden" name="enabled" value="1">
                                    @else
                                        <input type="hidden" name="enabled" value="0">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" id="enable_{{ $id }}"
                                                name="enabled" type="checkbox" role="switch" data-oauth-activate
                                                value="1" @checked($configValue('enabled', false))>
                                            <label class="form-check-label"
                                                for="enable_{{ $id }}">{{ __('admin.integrations.oauth.enable_after_setup') }}</label>
                                        </div>
                                        <div class="form-text">
                                            {{ __('admin.integrations.oauth.activation_help') }}
                                        </div>
                                    @endif
                                    @unless ($registry->globallyEnabled())
                                        <p class="form-text mb-0">{{ __('admin.integrations.oauth.global_paused_help') }}</p>
                                    @endunless
                                </div>
                                @include('partials.forms.sensitive-fields', [
                                    'user' => auth()->user(),
                                    'formKey' => 'oauth_config_' . $id,
                                ])
                            </x-forms.modal>
                        @endforeach
                    </div>

                </div>
            </div>
            <div class="tab-pane fade" id="oauth-recoveries" role="tabpanel" aria-labelledby="oauth-tab-recoveries">
                <div class="card-body">
                    <p>{{ $recoveryCount ? __('admin.integrations.oauth.recovery_pending', ['count' => $recoveryCount]) : __('admin.integrations.oauth.no_recovery') }}
                    </p>
                    <p class="text-body-secondary">{{ __('admin.integrations.oauth.recovery_help') }}</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-secondary"
                            href="{{ route('admin.settings.system.index') }}">{{ __('admin.settings.system.title') }}</a>
                        @if ($recoveryCount)
                            <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal"
                                data-bs-target="#oauth-recovery-retry">{{ __('admin.integrations.oauth.retry_recovery') }}</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
    <x-forms.modal id="oauth-add" :title="__('admin.integrations.oauth.additional')" :action="route('admin.settings.oauth.providers.store')">
        <p class="text-body-secondary">{{ __('admin.integrations.oauth.additional_help') }}</p>
        <div class="mb-3">
            <label class="form-label" for="connection_name">{{ __('admin.integrations.oauth.connection_name') }}</label>
            <input class="form-control" id="connection_name" name="name"
                value="{{ old('_modal') === 'oauth-add' ? old('name') : '' }}" minlength="2" maxlength="80" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="connection_adapter">{{ __('admin.integrations.oauth.service_type') }}</label>
            <select class="form-select" id="connection_adapter" name="adapter">
                @foreach ($adapters as $adapter)
                    <option value="{{ $adapter }}" @selected(old('adapter') === $adapter)>
                        {{ collect($registry->definitions())->firstWhere('adapter', $adapter)['name'] ?? ucfirst($adapter) }}
                    </option>
                @endforeach
            </select>
        </div>
        @include('partials.forms.sensitive-fields', ['user' => auth()->user(), 'formKey' => 'oauth_add'])
    </x-forms.modal>
    @if ($recoveryCount)
        <x-forms.modal id="oauth-recovery-retry" :title="__('admin.integrations.oauth.retry_recovery')" :action="route('admin.settings.oauth.recovery.retry')">
            <p>{{ __('admin.integrations.oauth.recovery_help') }}</p>
            @include('partials.forms.sensitive-fields', [
                'user' => auth()->user(),
                'formKey' => 'oauth_retry',
            ])
        </x-forms.modal>
    @endif
@endsection
