@extends('admin.layouts.layout')
@section('title', __('admin.servers.title'))
@section('description', __('admin.servers.description'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.servers.deliveries.index') }}">
        {{ __('admin.servers.deliveries.title') }}</a>
    @if (!empty($games))
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#server-new">
            {{ __('admin.servers.create') }}</button>
    @endif
@endsection
@section('content')
    <div id="admin-servers-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        @if (empty($games))
            <div class="alert alert-secondary mb-0">
                {{ __('admin.servers.link.none_available') }} <a
                    href="{{ url('/admin/games') }}">{{ __('site.games.title') }}</a>
            </div>
        @endif
        <section class="card">
            <x-tables.controls :paginator="$servers" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th data-table-sort="name">{{ __('admin.servers.name') }}</th>
                            <th class="text-center">{{ __('admin.servers.primary_badge') }}</th>
                            <th data-table-sort="host">{{ __('admin.servers.address') }}</th>
                            <th>{{ __('admin.servers.link.connection') }}</th>
                            <th data-table-sort="enabled">{{ __('common.labels.status') }}</th>
                            <th>{{ __('site.games.server_players') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($servers as $server)
                            <tr>
                                <td>
                                    <strong>{{ $server->name }}</strong>
                                </td>
                                <td class="text-center">
                                    @if ($server->id === (int) $primaryServerId)
                                        <span class="d-inline-block"
                                            title="{{ __('admin.servers.primary_selected', ['name' => $server->name]) }}">
                                            <button class="btn btn-primary btn-sm" type="button" disabled
                                                aria-label="{{ __('admin.servers.primary_selected', ['name' => $server->name]) }}">
                                                <i class="bi bi-star-fill" aria-hidden="true"></i>
                                            </button>
                                        </span>
                                    @else
                                        <button class="btn btn-outline-secondary btn-sm" type="button"
                                            data-bs-toggle="modal" data-bs-target="#server-primary-{{ $server->id }}"
                                            title="{{ __('admin.servers.primary', ['name' => $server->name]) }}"
                                            aria-label="{{ __('admin.servers.primary', ['name' => $server->name]) }}">
                                            <i class="bi bi-star" aria-hidden="true"></i>
                                        </button>
                                    @endif
                                </td>
                                <td><code>{{ str_contains($server->host, ':') ? '[' . $server->host . ']' : $server->host }}:{{ $server->port }}</code>
                                </td>
                                <td>{{ __($games[$server->game]['connections'][$server->connection] ?? $server->connection) }}
                                </td>
                                <td><span
                                        @class([
                                            'badge',
                                            'text-bg-success' => $server->enabled,
                                            'text-bg-secondary' => !$server->enabled,
                                        ])>{{ $server->enabled ? __('common.statuses.active') : __('common.statuses.inactive') }}</span>
                                </td>
                                <td class="text-end">
                                    @php
                                        $statusFresh = $server->hasFreshStatus();
                                        $onlinePlayers = $server->onlinePlayerCount();
                                    @endphp
                                    @if ($statusFresh && $onlinePlayers !== null)
                                        <span class="text-nowrap">{{ $onlinePlayers }} /
                                            {{ $server->status['max_players'] ?? '—' }}</span>
                                        <small class="d-block text-success">{{ __('site.games.server_online') }}</small>
                                    @else
                                        <span
                                            class="text-body-secondary small">{{ __($server->last_seen_at ? 'site.games.server_stale' : 'site.games.server_unchecked') }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2">
                                        @if (!empty($serverConnections[$server->game][$server->connection]['managed']))
                                            <a class="btn btn-outline-primary btn-sm text-nowrap"
                                                href="{{ route('admin.servers.link.show', $server) }}">{{ __('admin.servers.link.manage') }}</a>
                                        @endif
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                                data-bs-toggle="dropdown"
                                                aria-expanded="false">{{ __('common.labels.actions') }}</button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                @if ($server->id === (int) $primaryServerId)
                                                    <li>
                                                        <button class="dropdown-item" type="button" data-bs-toggle="modal"
                                                            data-bs-target="#server-primary-{{ $server->id }}">{{ __('admin.servers.remove_primary') }}</button>
                                                    </li>
                                                @endif
                                                <li><a class="dropdown-item"
                                                        href="{{ route('admin.servers.deliveries.index', ['server_id' => $server->id]) }}">{{ __('admin.servers.deliveries.title') }}</a>
                                                </li>
                                                @if (!empty($games))
                                                    <li>
                                                        <button class="dropdown-item" type="button" data-bs-toggle="modal"
                                                            data-bs-target="#server-{{ $server->id }}">{{ __('common.actions.edit') }}</button>
                                                    </li>
                                                    @if (!empty($serverTransports[$server->game][$server->connection]['commands']))
                                                        <li><a class="dropdown-item"
                                                                href="{{ route('admin.servers.link.show', $server) }}">{{ __('admin.servers.transport.queue') }}</a>
                                                        </li>
                                                    @endif
                                                    @if (!empty($serverTransports[$server->game][$server->connection]['status']))
                                                        <li>
                                                            <form data-ajax="true" data-redirect-url="response"
                                                                method="post"
                                                                action="{{ route('admin.servers.transport.check', $server) }}">
                                                                @csrf
                                                                <button class="dropdown-item"
                                                                    type="submit">{{ __('admin.servers.transport.check') }}</button>
                                                            </form>
                                                        </li>
                                                    @endif
                                                @endif
                                                <li>
                                                    <button class="dropdown-item text-danger" type="button"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#server-delete-{{ $server->id }}">{{ __('common.actions.delete') }}</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="7" :message="__('admin.servers.empty_help')" :description="__('admin.servers.empty')" :action="!empty($games) ? __('admin.servers.create') : null"
                                modal="server-new" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $servers->links() }}
            </div>
        </section>
        @foreach ($servers as $server)
            @if (!empty($games))
                @php
                    $editingConnection =
                        old('server_form') === (string) $server->id
                            ? old('connection', $server->connection)
                            : $server->connection;
                    $configureLink =
                        !empty($serverConnections[$server->game][$editingConnection]['managed']) &&
                        ($server->connection !== $editingConnection || $serverSetupRequired[$server->id]);
                @endphp
                <x-forms.modal :id="'server-' . $server->id" :title="$server->name" :action="route('admin.servers.store', $server)" :submit="__($configureLink ? 'admin.servers.link.save_and_configure' : 'common.actions.save')">
                    @include('admin.servers.partials.fields', ['editingServer' => $server])
                </x-forms.modal>
            @endif
            <x-forms.modal :id="'server-primary-' . $server->id" :title="__(
                $server->id === (int) $primaryServerId
                    ? 'admin.servers.remove_primary_confirm_title'
                    : 'admin.servers.primary_confirm_title',
            )" :action="route('admin.servers.primary', $server)" :method="$server->id === (int) $primaryServerId ? 'DELETE' : 'POST'" :submit="__('common.confirmation.confirm')"
                size="sm" variant="primary">
                <p>{{ __($server->id === (int) $primaryServerId ? 'admin.servers.remove_primary_confirm_help' : 'admin.servers.primary_confirm_help') }}
                </p>
                <p class="fw-semibold mb-1">{{ $server->name }}</p>
                <code class="text-break">{{ $server->publicAddress() }}</code>
            </x-forms.modal>
            <x-forms.modal :id="'server-delete-' . $server->id" :title="__('admin.servers.delete')" :action="route('admin.servers.destroy', $server)" method="DELETE" :submit="__('common.actions.delete')"
                size="sm">
                <p class="mb-0">{{ __('admin.servers.delete_confirm') }} <strong>{{ $server->name }}</strong>
                </p>
            </x-forms.modal>
        @endforeach
        @if (!empty($games))
            @php
                $newConnection = array_key_first($games[array_key_first($games)]['connections'] ?? []);
                $newConnection = old('server_form') === 'new' ? old('connection', $newConnection) : $newConnection;
            @endphp
            <x-forms.modal id="server-new" :title="__('admin.servers.create')" :action="route('admin.servers.store')" :submit="__(
                !empty($serverConnections[array_key_first($games)][$newConnection]['managed'])
                    ? 'admin.servers.link.save_and_configure'
                    : 'common.actions.save',
            )">
                @include('admin.servers.partials.fields', ['editingServer' => null])
            </x-forms.modal>
        @endif
    </div>
@endsection
