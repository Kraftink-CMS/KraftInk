@use('App\Exceptions\TransportFailure')
@use('Illuminate\Support\Str')
@extends('admin.layouts.layout')
@section('title', __('admin.servers.link.title', ['server' => $server->name]))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.servers.deliveries.index', ['server_id' => $server->id]) }}">
        {{ __('admin.servers.deliveries.title') }}</a>
    <a class="btn btn-outline-secondary" href="{{ route('admin.servers.index') }}">
        {{ __('admin.servers.title') }}</a>
@endsection
@section('content')
    @php
        $fields = $metadata['fields'] ?? [];
        $fieldValue = fn (string $field) => old(
            'transport_options.' . $field,
            $server->transport_options[$field] ?? ($fields[$field]['default'] ?? ''),
        );
        $choiceTargets = collect($fields)
            ->whereNotNull('shown_when')
            ->mapWithKeys(fn (array $definition, string $field) => [
                $definition['shown_when']['field'] => [
                    'id' => 'transport-' . $field . '-group',
                    'value' => $definition['shown_when']['value'],
                ],
            ])->all();
    @endphp
    <div class="vstack gap-4">
        <form data-ajax="true" data-redirect-url="response" class="card" method="post"
            action="{{ route('admin.servers.transport.configure', $server) }}">
            <div class="card-header">
                <h2 class="card-title">
                    {{ __('admin.servers.transport.configuration', ['connection' => __($connectionLabel)]) }}</h2>
            </div>
            <div class="card-body">
                @csrf
                @if (!empty($metadata['help']))
                    <div class="alert alert-secondary">
                        <p class="mb-0">{{ __($metadata['help']) }}</p>
                    </div>
                @endif
                @foreach ($fields as $field => $definition)
                    @php($condition = $definition['shown_when'] ?? null)
                    @if ($condition)
                        @php($visible = (string) $fieldValue($condition['field']) === (string) $condition['value'])
                        <fieldset class="border-0 m-0 p-0" id="transport-{{ $field }}-group"
                            @disabled(!$visible) @if (!$visible) hidden @endif>
                    @endif
                    @include('admin.servers.transport.partials.field', [
                        'fieldId' => 'transport-' . $field,
                        'value' => $fieldValue($field),
                        'originalValue' => null,
                        'choiceTarget' => $choiceTargets[$field] ?? null,
                    ])
                    @if ($condition)
                        </fieldset>
                    @endif
                @endforeach
                @if (!empty($metadata['secret']))
                    @include('admin.servers.transport.partials.secret', [
                        'fieldId' => 'transport-secret',
                        'secretLabel' => $metadata['secret_label'] ?? 'admin.servers.transport.secret',
                        'configured' => filled($server->secret),
                        'required' => blank($server->secret),
                    ])
                @endif
                @include('admin.servers.transport.partials.confirmation', [
                    'confirmationKey' => 'configuration',
                ])
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
            </div>
        </form>
        @if ($server->enabled && !$configurationRequired)
            <form data-ajax="true" data-redirect-url="response" class="card" method="post"
                action="{{ route('admin.servers.transport.enqueue', $server) }}">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.servers.link.console') }}</h2>
                </div>
                <div class="card-body">
                    @csrf
                    <input type="hidden" name="idempotency_key"
                        value="{{ old('idempotency_key', (string) Str::uuid()) }}">
                    <div class="mb-3">
                        <label class="form-label" for="command">{{ __('admin.servers.link.command') }}</label>
                        <input class="form-control" id="command" name="command" value="{{ old('command') }}"
                            maxlength="2048" required>
                    </div>
                    @include('admin.servers.transport.partials.confirmation', [
                        'confirmationKey' => 'queue',
                    ])
                </div>
                <div class="card-footer d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">{{ __('admin.servers.link.enqueue') }}</button>
                </div>
            </form>
        @elseif (!$server->enabled)
            <div class="alert alert-secondary mb-0">{{ __('admin.servers.transport.command_disabled') }}</div>
        @endif
        <div id="admin-transport-commands" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
            <section class="card">
                <x-tables.controls :paginator="$commands" container-class="card-body" />
                <div class="card-body p-0 table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="text-nowrap">
                            <tr>
                                <th data-table-sort="command">{{ __('admin.servers.link.command') }}</th>
                                <th data-table-sort="status">{{ __('common.labels.status') }}</th>
                                <th data-table-sort="attempts">{{ __('admin.servers.link.attempts') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($commands as $command)
                                <tr>
                                    <td class="text-break">{{ $command->command }}</td>
                                    <td>{{ __('admin.servers.transport.states.' . $command->status) }}
                                        @if ($command->last_error)
                                            <small
                                                class="d-block text-secondary">{{ TransportFailure::label($command->last_error) }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $command->attempts }}</td>
                                </tr>
                            @empty
                                <x-tables.empty :colspan="3" :message="__('admin.servers.transport.no_commands')" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    {{ $commands->links() }}
                </div>
            </section>
        </div>
    </div>
@endsection
