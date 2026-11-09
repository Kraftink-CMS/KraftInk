@use('Illuminate\Pagination\LengthAwarePaginator')
@php
    /** @var LengthAwarePaginator<int, array{name:string,type:string}> $unavailableExtensions */
@endphp
@extends('admin.layouts.layout')
@section('title', __('migration.report_title'))
@section('description', __('migration.report_description'))
@section('actions')
    <a class="btn btn-outline-secondary"
        href="{{ route('admin.imports.report.download', $run->id) }}">{{ __('migration.download_report') }}</a>
    <a class="btn btn-outline-secondary" href="{{ route('admin.imports.index') }}">{{ __('common.actions.back') }}</a>
@endsection
@section('content')
    <section class="card">
        <div class="card-body">
            <p>{{ app('translator')->has('system.imports.sources.' . $run->source) ? __('system.imports.sources.' . $run->source) : __('system.imports.source_other', ['name' => $run->source]) }}
                · <code>{{ $run->id }}</code></p>
            <p class="mb-0">{{ __('migration.review_hint') }}</p>
        </div>
    </section>
    <div id="import-unavailable-extensions" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        @if ($unavailableExtensions->total())
            <div class="alert alert-warning">
                <p class="mb-2">{{ __('migration.unavailable_extensions') }}</p>
                <ul class="mb-0">
                    @foreach ($unavailableExtensions as $extension)
                        <li>{{ $extension['name'] }} · {{ __('migration.extension_types.' . $extension['type']) }}</li>
                    @endforeach
                </ul>
            </div>
            {{ $unavailableExtensions->links() }}
        @endif
    </div>
    <div id="import-table-report" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('migration.data_title') }}</h2>
            </div>
            <x-tables.controls :paginator="$tables" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="name">{{ __('system.imports.source_data') }}</th>
                            <th scope="col" data-table-sort="count">{{ __('migration.source_rows') }}</th>
                            <th scope="col">{{ __('migration.converted_rows') }}</th>
                            <th scope="col">{{ __('migration.archive_rows') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tables as $details)
                            @php($table = $details['name'])
                            <tr>
                                <td><code>{{ $table }}</code></td>
                                <td>{{ $details['count'] }}</td>
                                <td>{{ $report['converted'][$table] ?? '—' }}</td>
                                <td>{{ $report['archived_records'][$table] ?? (in_array($table, $report['archive_only'] ?? [], true) ? $details['count'] : '—') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $tables->links() }}
            </div>
        </section>
    </div>
    <div id="import-notice-report" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('migration.follow_up') }}</h2>
            </div>
            <x-tables.controls :paginator="$notices" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="table">{{ __('migration.reference') }}</th>
                            <th scope="col">{{ __('migration.action') }}</th>
                            <th scope="col" data-table-sort="path">{{ __('migration.original_path') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($notices as $notice)
                            <tr>
                                <td>
                                    <code>{{ $notice['table'] ?? ($notice['plugin'] ?? '—') }}</code>
                                    @if (isset($notice['id']))
                                        <span>#{{ $notice['id'] }}</span>
                                    @endif
                                    @if (!empty($notice['field']))
                                        <span class="small text-body-secondary d-block">{{ $notice['field'] }}</span>
                                    @endif
                                    @if (!empty($notice['target_table']))
                                        <span class="small text-body-secondary d-block">{{ __('migration.destination') }} :
                                            <code>{{ $notice['target_table'] }} #{{ $notice['target_id'] }}</code></span>
                                    @endif
                                </td>
                                <td>{{ $presenter->guidance($notice) }}</td>
                                <td class="text-break"><code>{{ $notice['path'] ?? ($notice['name'] ?? '—') }}</code></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-body-secondary">{{ __('migration.no_notices') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $notices->links() }}
            </div>
        </section>
    </div>
@endsection
