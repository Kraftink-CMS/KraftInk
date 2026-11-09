@php
    $serverKey = (string) ($editingServer?->id ?? 'new');
    $serverOld = old('server_form') === $serverKey;
    $serverValue = fn($key, $fallback) => $serverOld ? old($key, $fallback) : $fallback;
    $serverGame = array_key_first($games);
    $serverConnection = $serverValue(
        'connection',
        $editingServer?->connection ?? array_key_first($games[$serverGame]['connections'] ?? []),
    );
    $serverActivationText = static function (string $code, array $capabilities): array {
        if ($code === 'kraftlink') {
            return [
                'label' => __('admin.servers.enabled'),
                'help' => __('admin.servers.enabled_help'),
            ];
        }
        $activation = !empty($capabilities['commands']) ? 'commands' : 'manual';

        return [
            'label' => __('admin.servers.activation.' . $activation . '.label'),
            'help' => __('admin.servers.activation.' . $activation . '.help'),
        ];
    };
    $serverEnabledText = $serverActivationText(
        $serverConnection,
        $serverConnections[$serverGame][$serverConnection] ?? [],
    );
    $serverHasPlayerStatus = !empty($serverConnections[$serverGame][$serverConnection]['status']);
    $serverStatusOnly = $serverHasPlayerStatus && empty($serverConnections[$serverGame][$serverConnection]['commands']);
    $serverShowPlayers = (bool) $serverValue('show_players', $editingServer?->show_players ?? true);
    if ($serverStatusOnly && !$serverOld) {
        $serverShowPlayers = $serverShowPlayers && ($editingServer?->enabled ?? true);
    }
    $serverConnectionNeedsSetup = fn(string $code): bool => !$editingServer ||
        $editingServer->connection !== $code ||
        $serverSetupRequired[$editingServer->id];
    $serverOriginal = $editingServer?->only(['game', 'connection', 'host', 'port']);
    $serverDestination = [
        'game' => $serverGame,
        'connection' => $serverConnection,
        'host' => $serverValue('host', $editingServer?->host),
        'port' => $serverValue('port', $editingServer?->port ?? ($games[$serverGame]['default_port'] ?? null)),
    ];
    $serverHasStoredSecret = filled($editingServer?->secret);
    $serverNeedsConfirmation =
        $serverHasStoredSecret &&
        array_any($serverDestination, fn($value, $field) => (string) $value !== (string) $editingServer->{$field});
@endphp
<div data-server-fields data-server-original='@json($serverOriginal)'>
    <input type="hidden" name="server_form" value="{{ $serverKey }}">
    <input type="hidden" name="game" data-server-game value="{{ $serverGame }}">
    <div class="mb-3">
        <label class="form-label" for="server_name_{{ $serverKey }}">{{ __('admin.servers.name') }}</label>
        <input class="form-control" id="server_name_{{ $serverKey }}" name="name"
            value="{{ $serverValue('name', $editingServer?->name) }}" required maxlength="100">
    </div>
    <fieldset class="mb-3 border-0 p-0">
        <legend class="col-form-label pt-0">{{ __('admin.servers.link.connection') }}</legend>
        <div class="table-responsive position-relative">
            <table class="table table-sm small align-middle mb-0">
                <thead class="text-nowrap">
                    <tr>
                        <th scope="col">{{ __('admin.servers.connection_comparison.mode') }}</th>
                        <th scope="col" class="text-center">
                            {{ __('admin.servers.connection_comparison.players') }}
                        </th>
                        <th scope="col" class="text-center">
                            {{ __('admin.servers.connection_comparison.commands') }}
                        </th>
                        <th scope="col" class="text-center">
                            {{ __('admin.servers.connection_comparison.presence') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($serverConnections[$serverGame] ?? [] as $connectionCode => $connectionCapabilities)
                        @php
                            $activationText = $serverActivationText($connectionCode, $connectionCapabilities);
                            $connectionNeedsSetup =
                                $connectionCapabilities['managed'] && $serverConnectionNeedsSetup($connectionCode);
                        @endphp
                        <tr data-server-connection-row @class(['table-active' => $serverConnection === $connectionCode])>
                            <th scope="row">
                                <div class="form-check mb-0">
                                    <input class="form-check-input"
                                        id="server_connection_{{ $serverKey }}_{{ $connectionCode }}"
                                        type="radio" name="connection" value="{{ $connectionCode }}"
                                        data-server-connection data-enabled-label="{{ $activationText['label'] }}"
                                        data-enabled-help="{{ $activationText['help'] }}"
                                        data-player-status="{{ $connectionCapabilities['status'] ? '1' : '0' }}"
                                        data-status-only="{{ $connectionCapabilities['status'] && !$connectionCapabilities['commands'] ? '1' : '0' }}"
                                        data-save-label="{{ __($connectionNeedsSetup ? 'admin.servers.link.save_and_configure' : 'common.actions.save') }}"
                                        @checked($serverConnection === $connectionCode) required>
                                    <label class="form-check-label fw-normal text-nowrap"
                                        for="server_connection_{{ $serverKey }}_{{ $connectionCode }}">{{ __($connectionCapabilities['label']) }}</label>
                                    @if ($connectionCode === 'kraftlink')
                                        <span
                                            class="d-block small text-body-secondary fw-normal text-nowrap">{{ __('admin.servers.connection_comparison.kraftlink_requirement') }}</span>
                                    @endif
                                </div>
                            </th>
                            @foreach (['status', 'commands', 'player_presence'] as $connectionCapability)
                                <td class="text-center">
                                    <i @class([
                                        'bi',
                                        'bi-check-lg' => $connectionCapabilities[$connectionCapability],
                                        'bi-dash-lg' => !$connectionCapabilities[$connectionCapability],
                                    ]) aria-hidden="true"></i>
                                    <span
                                        class="visually-hidden">{{ __($connectionCapabilities[$connectionCapability] ? 'admin.servers.connection_comparison.yes' : 'admin.servers.connection_comparison.no') }}</span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </fieldset>
    @foreach ($serverConnections[$serverGame] ?? [] as $connectionCode => $connectionCapabilities)
        @if ($connectionCapabilities['managed'])
            <div data-server-transport-game="{{ $serverGame }}" data-server-transport-mode="{{ $connectionCode }}"
                data-server-needs-secret="0" @if ($serverConnection !== $connectionCode) hidden @endif>
                <div class="alert alert-info">
                    <p class="mb-0">
                        {{ __($serverConnectionNeedsSetup($connectionCode) ? 'admin.servers.transport.setup_help' : 'admin.servers.transport.setup_edit_help', ['connection' => __($connectionCapabilities['label'])]) }}
                    </p>
                </div>
            </div>
        @endif
    @endforeach
    <div class="row g-3">
        <div class="mb-3 col-md-6">
            <label class="form-label" for="server_host_{{ $serverKey }}">{{ __('admin.servers.address') }}</label>
            <input class="form-control" id="server_host_{{ $serverKey }}" name="host"
                value="{{ $serverValue('host', $editingServer?->host) }}" required maxlength="255"
                placeholder="play.example.org">
            <small class="form-text">{{ __('admin.servers.address_help') }}</small>
        </div>
        <div class="mb-3 col-md-6">
            <label class="form-label" for="server_port_{{ $serverKey }}">{{ __('common.labels.port') }}</label>
            <input class="form-control" id="server_port_{{ $serverKey }}" type="number" name="port"
                data-server-port
                value="{{ $serverValue('port', $editingServer?->port ?? ($games[$serverGame]['default_port'] ?? '')) }}"
                required min="1" max="65535">
            <input type="hidden" name="show_port" value="0">
            <div class="form-check mt-2">
                <input class="form-check-input" id="server_show_port_{{ $serverKey }}" type="checkbox"
                    name="show_port" value="1" @checked((bool) $serverValue('show_port', $editingServer?->show_port ?? true))>
                <label class="form-check-label"
                    for="server_show_port_{{ $serverKey }}">{{ __('admin.servers.show_port') }}</label>
            </div>
        </div>
    </div>
    <fieldset class="mb-3 border-0 p-0" data-server-player-display @disabled(!$serverHasPlayerStatus)
        @if (!$serverHasPlayerStatus) hidden @endif>
        <input type="hidden" name="show_players" value="0">
        <div class="form-check">
            <input class="form-check-input" id="server_show_players_{{ $serverKey }}" type="checkbox"
                name="show_players" value="1" @checked($serverShowPlayers)>
            <label class="form-check-label"
                for="server_show_players_{{ $serverKey }}">{{ __('admin.servers.show_players') }}</label>
        </div>
    </fieldset>
    @foreach ($serverTransports ?? [] as $transportGame => $transportModes)
        @foreach ($transportModes as $transportMode => $transportMetadata)
            @continue(!empty($serverConnections[$transportGame][$transportMode]['managed']))
            @php
                $transportHasConfiguration =
                    !empty($transportMetadata['fields']) || !empty($transportMetadata['secret']);
            @endphp
            <fieldset @class([
                'border-0 p-0' => !$transportHasConfiguration,
            ]) data-server-transport-game="{{ $transportGame }}"
                data-server-transport-mode="{{ $transportMode }}"
                data-server-needs-secret="{{ !empty($transportMetadata['secret']) ? '1' : '0' }}"
                @disabled($serverGame !== $transportGame || $serverConnection !== $transportMode) @if ($serverGame !== $transportGame || $serverConnection !== $transportMode) hidden @endif>
                @if (!empty($transportMetadata['help']))
                    <div @class([
                        'alert',
                        'alert-secondary' => $transportHasConfiguration,
                        'alert-info' => !$transportHasConfiguration,
                    ])>
                        <p class="mb-0">{{ __($transportMetadata['help']) }}</p>
                    </div>
                @endif
                @foreach ($transportMetadata['fields'] ?? [] as $transportField => $transportDefinition)
                    @php($transportOriginalValue = $editingServer?->transport_options[$transportField] ?? ($transportDefinition['default'] ?? ''))
                    @include('admin.servers.transport.partials.field', [
                        'field' => $transportField,
                        'definition' => $transportDefinition,
                        'fieldId' => 'server_' . $transportMode . '_' . $transportField . '_' . $serverKey,
                        'value' => $serverValue('transport_options.' . $transportField, $transportOriginalValue),
                        'originalValue' => $transportOriginalValue,
                    ])
                @endforeach
                @if (!empty($transportMetadata['secret']))
                    @include('admin.servers.transport.partials.secret', [
                        'fieldId' => 'server_secret_' . $transportMode . '_' . $serverKey,
                        'secretLabel' => $transportMetadata['secret_label'] ?? 'admin.servers.transport.secret',
                        'configured' => filled($editingServer?->secret),
                        'required' => false,
                    ])
                @endif
            </fieldset>
        @endforeach
    @endforeach
    <fieldset class="d-none" data-server-confirmation
        data-server-has-secret="{{ $serverHasStoredSecret ? '1' : '0' }}" @disabled(!$serverNeedsConfirmation)
        @if (!$serverNeedsConfirmation) hidden @endif>
        @include('admin.servers.transport.partials.confirmation', [
            'confirmationKey' => 'server_' . $serverKey,
        ])
    </fieldset>
    <fieldset class="mb-3 border-0 p-0" data-server-activation @disabled($serverStatusOnly)
        @if ($serverStatusOnly) hidden @endif>
        <input type="hidden" name="enabled" value="0">
        <div class="form-check">
            <input class="form-check-input" id="server_enabled_{{ $serverKey }}" type="checkbox" name="enabled"
                value="1" aria-describedby="server_enabled_help_{{ $serverKey }}"
                @checked((bool) $serverValue('enabled', $editingServer?->enabled ?? true))>
            <label class="form-check-label" for="server_enabled_{{ $serverKey }}"
                data-server-enabled-label>{{ $serverEnabledText['label'] }}</label>
        </div>
        <div class="form-text" id="server_enabled_help_{{ $serverKey }}" data-server-enabled-help>
            {{ $serverEnabledText['help'] }}
        </div>
    </fieldset>
    <x-admin::translation-fields :id="'server-translations-' . $serverKey" :fields="['name' => ['label' => __('common.labels.name'), 'max' => 100]]" :values="$editingServer?->translations ?? []" :use-old="$serverOld" />
</div>
