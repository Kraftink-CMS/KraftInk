@use('App\Kraft\Audit')
@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('admin.operations.audit'))
@section('description', __('admin.operations.audit_description'))
@section('actions')
    <a class="btn btn-outline-secondary"
        href="{{ route('admin.operations.audit.export', request()->only(['action', 'user_id', 'from', 'to'])) }}">
        {{ __('admin.operations.export') }}</a>
@endsection
@section('content')
    <div id="admin-audit-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-body">
                <x-tables.controls :paginator="$entries" class="mb-3" />
                <form method="get" action="{{ route('admin.operations.audit.index') }}" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" for="action">{{ __('common.labels.action') }}</label>
                        <input class="form-control" id="action" name="action" value="{{ request('action') }}"
                            maxlength="100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="user_id">{{ __('admin.operations.actor_id') }}</label>
                        <input class="form-control" id="user_id" type="number" name="user_id"
                            value="{{ request('user_id') }}" min="1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="from">{{ __('admin.operations.from') }}</label>
                        <input class="form-control" id="from" type="date" name="from" value="{{ request('from') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="to">{{ __('admin.operations.to') }}</label>
                        <input class="form-control" id="to" type="date" name="to" value="{{ request('to') }}">
                    </div>
                    <noscript>
                        <div class="col-md-2">
                            <button class="btn btn-primary" type="submit">{{ __('common.actions.search') }}</button>
                        </div>
                    </noscript>
                </form>
                <p class="form-text mb-0">{{ __('common.dates.display_zone', ['zone' => app(SiteDates::class)->timezone()]) }}</p>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="created_at">{{ __('common.labels.date') }}</th>
                            <th scope="col" data-table-sort="action">{{ __('common.labels.action') }}</th>
                            <th scope="col">{{ __('admin.operations.actor') }}</th>
                            <th scope="col" data-table-sort="ip">{{ __('admin.operations.ip') }}</th>
                            <th scope="col">{{ __('common.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($entries as $entry)
                            <tr>
                                <td>{{ app(SiteDates::class)->format($entry->created_at, 'Y-m-d H:i:s T') }}</td>
                                <td class="text-break">{{ app(Audit::class)->label($entry->action) }}</td>
                                <td>{{ $entry->actor?->username ?? __('admin.operations.system') }}</td>
                                <td>{{ $entry->ip }}</td>
                                <td><a class="btn btn-outline-secondary btn-sm"
                                        href="{{ route('admin.operations.audit.show', $entry) }}">{{ __('admin.operations.details') }}</a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">{{ __('admin.operations.no_entries') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $entries->links() }}
            </div>
        </section>
    </div>
    <div class="row g-4">
        <div class="col-md-6">
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.operations.retention') }}</h2>
                </div>
                <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.operations.audit.retention') }}">
                    <div class="card-body">
                        @csrf
                        <label class="form-label" for="retention_days">{{ __('admin.operations.retention_days') }}</label>
                        <input class="form-control" id="retention_days" type="number" name="retention_days" min="7"
                            max="3650" value="{{ old('retention_days', $retentionDays) }}" required>
                        <p class="form-text mb-0">{{ __('admin.operations.retention_help') }}</p>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                    </div>
                </form>
            </section>
        </div>
        <div class="col-md-6">
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.operations.purge') }}</h2>
                </div>
                <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.operations.audit.purge') }}">
                    <div class="card-body">
                        @csrf
                        <p>{{ __('admin.operations.purge_help', ['days' => $retentionDays]) }}</p>
                        @include('partials.forms.sensitive-fields', ['user' => auth()->user(), 'formKey' => 'audit_purge'])
                        <div class="form-check">
                            <input class="form-check-input" id="confirmed" type="checkbox" name="confirmed" value="1"
                                required>
                            <label class="form-check-label" for="confirmed">{{ __('admin.operations.confirm_purge') }}</label>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-outline-danger" type="submit">{{ __('admin.operations.purge') }}</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
@endsection
