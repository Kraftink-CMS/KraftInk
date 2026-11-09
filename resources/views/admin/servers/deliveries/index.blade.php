@use('App\Kraft\SiteDates')
@use('Illuminate\Support\Facades\Lang')
@extends('admin.layouts.layout')
@section('title', __('admin.servers.deliveries.title'))
@section('description', __('admin.servers.deliveries.help'))
@section('actions')
    @if (auth()->user()->hasPermission('servers.manage'))
        <a class="btn btn-outline-secondary" href="{{ route('admin.servers.index') }}">{{ __('admin.servers.title') }}</a>
    @endif
@endsection
@section('content')
    <div id="admin-deliveries-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <p class="text-body-secondary">{{ __('admin.servers.deliveries.setup_help') }}</p>
        <section class="card">
            <div class="card-body border-bottom">
                <x-tables.controls :paginator="$deliveries" class="mb-3" />
                <form class="row g-2 align-items-end" method="get" action="{{ route('admin.servers.deliveries.index') }}">
                    <div class="col-md-3">
                        <label class="form-label" for="delivery-source">{{ __('admin.servers.deliveries.source') }}</label>
                        <select class="form-select" id="delivery-source" name="source">
                            <option value="">{{ __('admin.servers.deliveries.all_sources') }}</option>
                            @foreach ($sources as $id => $source)
                                <option value="{{ $id }}" @selected(($filters['source'] ?? '') === $id)>{{ __($source['label']) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="delivery-server">{{ __('admin.servers.deliveries.server') }}</label>
                        <select class="form-select" id="delivery-server" name="server_id">
                            <option value="">{{ __('admin.servers.deliveries.all_servers') }}</option>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}" @selected((int) ($filters['server_id'] ?? 0) === $server->id)>{{ $server->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="delivery-status">{{ __('admin.servers.deliveries.status') }}</label>
                        <select class="form-select" id="delivery-status" name="status">
                            <option value="">{{ __('admin.servers.deliveries.all_states') }}</option>
                            <option value="attention" @selected(($filters['status'] ?? '') === 'attention')>
                                {{ __('admin.servers.deliveries.attention') }}</option>
                            @foreach ($deliveryStatuses as $state => $label)
                                <option value="{{ $state }}" @selected(($filters['status'] ?? '') === $state)>{{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="delivery-search">{{ __('admin.servers.deliveries.search') }}</label>
                        <input class="form-control" id="delivery-search" name="search" maxlength="180"
                            value="{{ $filters['search'] ?? '' }}">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <noscript>
                            <button class="btn btn-primary"
                                type="submit">{{ __('admin.servers.deliveries.filter') }}</button>
                        </noscript>
                        <a class="btn btn-outline-secondary"
                            href="{{ route('admin.servers.deliveries.index') }}">{{ __('admin.servers.deliveries.reset') }}</a>
                    </div>
                </form>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="source">{{ __('admin.servers.deliveries.source') }}</th>
                            <th scope="col" data-table-sort="player_name">{{ __('admin.servers.deliveries.player') }}
                            </th>
                            <th scope="col" data-table-sort="server_id">{{ __('admin.servers.deliveries.server') }}</th>
                            <th scope="col" data-table-sort="command">{{ __('admin.servers.deliveries.command') }}</th>
                            <th scope="col" data-table-sort="status">{{ __('admin.servers.deliveries.status') }}</th>
                            <th scope="col" data-table-sort="created_at">{{ __('admin.servers.deliveries.dates') }}</th>
                            <th scope="col" class="text-end">{{ __('admin.servers.deliveries.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($deliveries as $delivery)
                            @php
                                $destination = $servers->get($delivery->server_id ?: $defaultServer);
                                $retryable = $delivery->needs_attention && !empty($delivery->details['retry_url']);
                            @endphp
                            <tr>
                                <td>
                                    <small
                                        class="d-block text-body-secondary">{{ __($sources[$delivery->source]['label']) }}</small>
                                    @if (!empty($delivery->details['url']))
                                        <a href="{{ $delivery->details['url'] }}"
                                            title="{{ $delivery->reference }}">{{ $delivery->details['reference'] }}</a>
                                    @else
                                        <span>{{ $delivery->details['reference'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $delivery->player_name ?: __('admin.servers.deliveries.unknown_player') }}
                                    @if ($delivery->requires_online)
                                        <small
                                            class="d-block text-body-secondary">{{ __('admin.servers.deliveries.requires_online') }}</small>
                                    @endif
                                </td>
                                <td>{{ $destination?->name ?? ($delivery->server_id ? __('admin.servers.deliveries.missing_server') : __('admin.servers.deliveries.no_default_server')) }}
                                </td>
                                <td>
                                    <code class="text-break">{{ $delivery->command }}</code>
                                    <details class="small mt-1" @if ($delivery->needs_attention && $delivery->last_error) open @endif>
                                        <summary>{{ __('admin.servers.deliveries.details') }}</summary>
                                        <p class="text-break mb-1">{{ __('admin.servers.deliveries.reference') }} :
                                            {{ $delivery->id }}</p>
                                        @if ($delivery->last_error)
                                            <p class="text-break mb-0">{{ __('admin.servers.deliveries.error') }} :
                                                {{ $delivery->last_error }}</p>
                                        @endif
                                    </details>
                                </td>
                                <td>
                                    <span
                                        @class([
                                            'badge',
                                            'text-bg-success' => $delivery->status === 'delivered',
                                            'text-bg-danger' => $delivery->needs_attention,
                                            'text-bg-secondary' =>
                                                !$delivery->needs_attention && $delivery->status !== 'delivered',
                                        ])>{{ $delivery->details['status_label'] ?? (Lang::has('admin.servers.deliveries.states.' . $delivery->status) ? __('admin.servers.deliveries.states.' . $delivery->status) : __('admin.servers.deliveries.unknown_state')) }}</span>
                                    <small
                                        class="d-block text-body-secondary">{{ trans_choice('admin.servers.deliveries.attempts', $delivery->attempts, ['count' => $delivery->attempts]) }}</small>
                                    @if ($delivery->status === 'processing' && $delivery->needs_attention)
                                        <small
                                            class="d-block text-danger">{{ __('admin.servers.deliveries.expired') }}</small>
                                    @elseif ($delivery->status === 'pending' && $delivery->needs_attention)
                                        <small
                                            class="d-block text-danger">{{ __('admin.servers.deliveries.exhausted') }}</small>
                                    @elseif ($delivery->status === 'pending')
                                        <small
                                            class="d-block text-body-secondary">{{ __('admin.servers.deliveries.pending_' . $delivery->pending_reason) }}</small>
                                    @endif
                                </td>
                                <td>
                                    <small
                                        class="d-block">{{ __('admin.servers.deliveries.created_at', ['date' => app(SiteDates::class)->format($delivery->created_at)]) }}</small>
                                    @if ($delivery->delivered_at)
                                        <small
                                            class="d-block">{{ $delivery->details['delivered_label'] ?? __('admin.servers.deliveries.delivered_at', ['date' => app(SiteDates::class)->format($delivery->delivered_at)]) }}</small>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($retryable)
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal"
                                            data-bs-target="#delivery-retry-{{ $delivery->source }}-{{ $delivery->id }}">{{ __('admin.servers.deliveries.retry') }}</button>
                                    @endif
                                    @if ($delivery->status === 'pending' && !empty($delivery->details['cancel_url']))
                                        <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal"
                                            data-bs-target="#delivery-cancel-{{ $delivery->source }}-{{ $delivery->id }}">{{ __('admin.servers.deliveries.cancel') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="7" :message="__('admin.servers.deliveries.empty')" :description="__('admin.servers.deliveries.empty_help')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $deliveries->links() }}
            </div>
        </section>
        @foreach ($deliveries as $delivery)
            @if ($delivery->status === 'pending' && !empty($delivery->details['cancel_url']))
                <x-forms.modal :id="'delivery-cancel-' . $delivery->source . '-' . $delivery->id" :title="__('admin.servers.deliveries.cancel')" :action="$delivery->details['cancel_url']" :submit="__('admin.servers.deliveries.cancel')" :redirect-url="false"
                    data-ajax-refresh="admin-deliveries-list">
                    <p>{{ __('admin.servers.deliveries.cancel_help') }}</p>
                    <code class="d-block text-break mb-3">{{ $delivery->command }}</code>
                    <input type="hidden" name="confirm_cancel" value="1">
                    @include('partials.forms.sensitive-fields', [
                        'user' => auth()->user(),
                        'formKey' => 'delivery-cancel-' . $delivery->source . '-' . $delivery->id,
                    ])
                </x-forms.modal>
            @endif
            @if ($delivery->needs_attention && !empty($delivery->details['retry_url']))
                <x-forms.modal :id="'delivery-retry-' . $delivery->source . '-' . $delivery->id" :title="__('admin.servers.deliveries.retry')" :action="$delivery->details['retry_url']" :submit="__('admin.servers.deliveries.retry')" :redirect-url="false"
                    data-ajax-refresh="admin-deliveries-list">
                    <p>{{ __('admin.servers.deliveries.retry_help') }}</p>
                    <code class="d-block text-break mb-3">{{ $delivery->command }}</code>
                    @include('partials.forms.sensitive-fields', [
                        'user' => auth()->user(),
                        'formKey' => 'delivery-' . $delivery->source . '-' . $delivery->id,
                    ])
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="confirm_retry" value="1"
                            id="retry-{{ $delivery->source }}-{{ $delivery->id }}" required>
                        <label class="form-check-label"
                            for="retry-{{ $delivery->source }}-{{ $delivery->id }}">{{ __('admin.servers.deliveries.retry_confirm') }}</label>
                    </div>
                </x-forms.modal>
            @endif
        @endforeach
    </div>
@endsection
