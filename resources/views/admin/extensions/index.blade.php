@extends('admin.layouts.layout')
@section('title', __($type === 'theme' ? 'system.extensions.themes_title' : 'system.extensions.plugins_title'))
@section('description', __('system.extensions.lifecycle_help'))
@section('actions')
    <button class="btn btn-primary" type="button" data-bs-toggle="modal"
        data-bs-target="#extension-package">{{ __('admin.extensions.install') }}</button>
@endsection
@section('content')
    @if (config('kraft.safe_mode'))
        <div class="alert alert-secondary">
            {{ __('system.extensions.safe_mode') }}
        </div>
    @endif
    @if ($diagnostics)
        <div class="alert alert-danger">
            <strong>{{ __('system.extensions.diagnostics') }}</strong>
            <ul class="mb-0">
                @foreach ($diagnostics as $id => $message)
                    <li>{{ $id }} : {{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($type === 'theme')
        <div class="row g-3 mb-3">
            @foreach ($selectedThemes as $target => $selectedTheme)
                <div class="col-xl-6">
                    <section class="card h-100 mb-0">
                        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <h2 class="h5">{{ __('system.extensions.native_titles.' . $target) }}</h2>
                                <p class="text-body-secondary mb-0">
                                    {{ __('system.extensions.native_descriptions.' . $target) }}</p>
                            </div>
                            @if (!$selectedTheme)
                                <span class="badge text-bg-success">{{ __('common.statuses.active') }}</span>
                            @else
                                <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.extensions.themes.select') }}">
                                    @csrf
                                    <input type="hidden" name="target" value="{{ $target }}">
                                    <button class="btn btn-outline-secondary"
                                        type="submit">{{ __('system.extensions.use_themes.' . $target) }}</button>
                                </form>
                            @endif
                        </div>
                    </section>
                </div>
            @endforeach
        </div>
    @endif
    @include('admin.extensions.partials.list')
    <div id="admin-retired-extensions" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        @if ($retired->total())
            <section class="card" id="retired-extensions">
                <div class="card-header">
                    <h2 class="card-title">{{ __('system.extensions.retired_title') }}</h2>
                </div>
                <div class="card-body">
                    <p class="text-body-secondary mb-0">{{ __('system.extensions.retired_help') }}</p>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('common.labels.name') }}</th>
                                <th>{{ __('system.extensions.package_version') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($retired as $extension)
                                <tr>
                                    <td><strong>{{ $extension['name'] }}</strong><small
                                            class="d-block text-body-secondary">{{ $extension['id'] }}</small>
                                        @if (!$extension['valid'])
                                            <p class="text-danger small mb-0">{{ $extension['error'] }}</p>
                                        @endif
                                    </td>
                                    <td>{{ $extension['version'] }}</td>
                                    <td class="text-end">
                                        @if ($extension['valid'])
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                data-bs-toggle="modal" data-bs-target="#restore-{{ $extension['id'] }}"><i
                                                    class="bi bi-arrow-counterclockwise me-1"
                                                    aria-hidden="true"></i>{{ __('system.extensions.restore') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            {{ $retired->links() }}
            @foreach ($retired as $extension)
                @if ($extension['valid'])
                    <x-forms.modal :id="'restore-' . $extension['id']" :title="__('system.extensions.restore') . ' · ' . $extension['name']" :action="route('admin.extensions.restore', $extension['id'])" :submit="__('system.extensions.restore')">
                        <p>{{ __('system.extensions.restore_help') }}</p>
                        @include('partials.forms.confirm-action', [
                            'confirmationId' => 'restore-' . $extension['id'],
                        ])
                    </x-forms.modal>
                @endif
            @endforeach
        @endif
    </div>
    @include('admin.extensions.partials.package')
@endsection
