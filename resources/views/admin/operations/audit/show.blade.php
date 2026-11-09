@use('App\Kraft\Audit')
@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('admin.operations.entry_title', ['id' => $entry->id]))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.operations.audit.index') }}">{{ __('admin.operations.back') }}</a>
@endsection
@section('content')
    <section class="card">
        <div class="card-body">
            <dl class="row">
                <dt class="col-sm-3">{{ __('common.labels.action') }}</dt>
                <dd class="col-sm-9 text-break">{{ app(Audit::class)->label($entry->action) }}</dd>
                <dt class="col-sm-3">{{ __('common.labels.date') }}</dt>
                <dd class="col-sm-9">{{ app(SiteDates::class)->format($entry->created_at, 'Y-m-d H:i:s T') }}</dd>
                <dt class="col-sm-3">{{ __('admin.operations.actor') }}</dt>
                <dd class="col-sm-9">{{ $entry->actor?->username ?? __('admin.operations.system') }}</dd>
                <dt class="col-sm-3">{{ __('admin.operations.ip') }}</dt>
                <dd class="col-sm-9">{{ $entry->ip }}</dd>
            </dl>
            <h2 class="h6">{{ __('admin.operations.context') }}</h2>
            <pre class="bg-body-tertiary rounded p-3 overflow-auto mb-0">{{ json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>
        </div>
    </section>
@endsection
