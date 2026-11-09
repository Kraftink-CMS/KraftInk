@use('App\Kraft\Audit')
@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('admin.dashboard.title'))
@section('description', __('admin.dashboard.description'))
@php
    $dashboardPermissions = auth()->user()->effectiveRoles()->flatMap(fn($role) => $role->permissions)->unique();
    $can = fn($permission) => $dashboardPermissions->contains('*') || $dashboardPermissions->contains($permission);
@endphp
@section('actions')
    @if ($can('content.manage'))
        <a class="btn btn-primary" href="{{ route('admin.news.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('admin.news.create') }}</a>
    @endif
@endsection
@section('content')
    <x-extensions.slot name="admin.dashboard.before" :context="['stats' => $stats]" />
    <div class="row g-3">
        <x-extensions.slot name="admin.dashboard.metrics" :context="['stats' => $stats]">
            <x-admin::stat :label="__('admin.dashboard.members')" :value="$stats['users']" icon="people" :url="$can('users.manage') ? route('admin.users.index') : null" />
            <x-admin::stat :label="__('common.labels.news_plural')" :value="$stats['news']" icon="newspaper" color="info" :url="$can('content.manage') ? route('admin.news.index') : null" />
            <x-admin::stat :label="__('admin.servers.title')" :value="$stats['servers']" icon="hdd-network" color="secondary" :url="$can('servers.manage') ? route('admin.servers.index') : null" />
            <x-admin::stat :label="__('admin.dashboard.extensions')" :value="$stats['extensions']" icon="puzzle" color="success" :url="$can('extensions.manage') ? route('admin.extensions.plugins.index') : null" />
        </x-extensions.slot>
    </div>
    @if ($statistics)
        <x-extensions.slot name="admin.dashboard.statistics" :context="['statistics' => $statistics]">
            <section aria-labelledby="statistics-title">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h2 class="h5 mb-1" id="statistics-title">{{ __('admin.statistics.title') }}</h2>
                        <p class="small text-body-secondary mb-0">
                            {{ __('admin.statistics.help', ['zone' => $statistics['timezone']]) }}</p>
                    </div>
                    <form method="get" action="{{ route('admin.dashboard.index') }}" class="d-flex align-items-center gap-2">
                        <label class="visually-hidden" for="statistics-period">{{ __('admin.statistics.period') }}</label>
                        <select class="form-select form-select-sm" id="statistics-period" name="period">
                            @foreach ([7, 30, 90] as $period)
                                <option value="{{ $period }}" @selected($statistics['period'] === $period)>
                                    {{ __('admin.statistics.days', ['days' => $period]) }}</option>
                            @endforeach
                        </select>
                        <button type="submit"
                            class="btn btn-outline-secondary btn-sm">{{ __('admin.statistics.apply') }}</button>
                    </form>
                </div>
                <div class="row g-3">
                    <div class="col-lg-6">
                        <x-admin::charts.activity id="registrations" :title="__('admin.statistics.registrations')" :days="$statistics['days']" :values="$statistics['registrations']"
                            :timezone="$statistics['timezone']" />
                    </div>
                    <div class="col-lg-6">
                        <x-admin::charts.activity id="logins" :title="__('admin.statistics.logins')" :days="$statistics['days']" :values="$statistics['logins']"
                            :timezone="$statistics['timezone']" />
                    </div>
                </div>
            </section>
        </x-extensions.slot>
    @endif
    <div class="row g-4 align-items-start">
        <div class="col-lg-8">
            <x-extensions.slot name="admin.dashboard.activity" :context="['logs' => $logs]">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('admin.dashboard.recent_activity') }}</h2>
                        @if ($can('settings.manage'))
                            <div class="card-tools">
                                <a class="btn btn-sm btn-outline-secondary"
                                    href="{{ route('admin.operations.audit.index') }}">{{ __('admin.operations.audit') }}</a>
                            </div>
                        @endif
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>{{ __('common.labels.action') }}</th>
                                    <th>{{ __('common.labels.date') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($logs as $log)
                                    <tr>
                                        <td>{{ app(Audit::class)->label($log->action) }}</td>
                                        <td>{{ app(SiteDates::class)->format($log->created_at, 'd M Y · H:i T') }}
                                        </td>
                                    </tr>
                                @empty
                                    <x-tables.empty :colspan="2" :message="__('admin.dashboard.empty_activity')" :description="__('admin.dashboard.activity_help')" />
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </x-extensions.slot>
        </div>
        <div class="col-lg-4">
            <x-extensions.slot name="admin.dashboard.actions">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('admin.dashboard.quick_links') }}</h2>
                    </div>
                    <div class="list-group list-group-flush">
                        @foreach ([['route' => 'admin.news.create', 'label' => 'admin.dashboard.next_post', 'help' => 'admin.dashboard.next_post_help', 'icon' => 'file-earmark-plus', 'permission' => 'content.manage'], ['route' => 'admin.users.index', 'label' => 'admin.dashboard.community', 'help' => 'admin.dashboard.members_help', 'icon' => 'people', 'permission' => 'users.manage'], ['route' => 'admin.extensions.plugins.index', 'label' => 'admin.extensions.title', 'help' => 'admin.dashboard.extensions_help', 'icon' => 'puzzle', 'permission' => 'extensions.manage'], ['route' => 'admin.settings.site.index', 'label' => 'admin.settings.title', 'help' => 'admin.dashboard.settings_help', 'icon' => 'sliders', 'permission' => 'settings.manage']] as $link)
                            @if ($can($link['permission']))
                                <a class="list-group-item list-group-item-action d-flex align-items-start gap-3 py-3"
                                    href="{{ route($link['route']) }}"><i class="bi bi-{{ $link['icon'] }} mt-1"
                                        aria-hidden="true"></i><span><strong
                                            class="d-block">{{ __($link['label']) }}</strong><span
                                            class="d-block text-body-secondary small">{{ __($link['help']) }}</span></span></a>
                            @endif
                        @endforeach
                    </div>
                </section>
            </x-extensions.slot>
        </div>
    </div>
    <x-extensions.slot name="admin.dashboard.after" :context="['stats' => $stats]" />
@endsection
