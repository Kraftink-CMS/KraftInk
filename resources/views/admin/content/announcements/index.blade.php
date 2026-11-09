@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('admin.integrations.announcements.title'))
@section('description', __('admin.integrations.announcements.description'))
@section('content')
    @php
        $editingWebhook = $errors->hasAny(['webhook_url', 'password', 'code']);
    @endphp
    <form class="card mb-0" method="post" action="{{ route('admin.content.announcements.store') }}" data-ajax="true"
        data-redirect-url="response">
        @csrf
        <div class="card-header">
            <h2 class="card-title">{{ __('admin.integrations.announcements.settings') }}</h2>
        </div>
        <div class="card-body">
            <input type="hidden" name="enabled" value="0">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" id="announcement-enabled" type="checkbox" name="enabled" value="1"
                    role="switch" aria-describedby="announcement-status" @checked(old('enabled', $enabled))>
                <label class="form-check-label"
                    for="announcement-enabled">{{ __('admin.integrations.announcements.enabled') }}</label>
                <div class="form-text" id="announcement-status">
                    {{ $enabled ? __('admin.integrations.announcements.active') : __('admin.integrations.announcements.inactive') }}
                </div>
            </div>
            @if ($hasWebhook)
                <div class="border rounded p-3">
                    <div class="d-flex align-items-start gap-3">
                        <i class="bi bi-check-circle-fill text-success fs-4" aria-hidden="true"></i>
                        <div>
                            <h3 class="h6 mb-1">{{ __('admin.integrations.announcements.webhook_configured') }}</h3>
                            <p class="small text-body-secondary mb-0">
                                {{ __('admin.integrations.announcements.webhook_destination') }}</p>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse"
                            data-bs-target="#announcement-webhook-editor" aria-controls="announcement-webhook-editor"
                            aria-expanded="{{ $editingWebhook ? 'true' : 'false' }}">
                            {{ __('admin.integrations.announcements.replace_webhook') }}
                        </button>
                        <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal"
                            data-bs-target="#announcement-delete">
                            {{ __('common.actions.delete') }}
                        </button>
                    </div>
                    <div @class(['collapse', 'show' => $editingWebhook]) id="announcement-webhook-editor" data-announcement-editor>
                        <fieldset class="mt-3" data-announcement-fields @disabled(!$editingWebhook)>
                            @include('admin.content.announcements.partials.webhook', ['replacing' => true])
                        </fieldset>
                    </div>
                </div>
            @else
                <fieldset data-announcement-fields>
                    @include('admin.content.announcements.partials.webhook', ['replacing' => false])
                </fieldset>
            @endif
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
        </div>
    </form>
    @if ($hasWebhook)
        <x-forms.modal id="announcement-delete" :title="__('admin.integrations.announcements.delete_webhook')" :action="route('admin.content.announcements.store')" :submit="__('common.actions.delete')" variant="danger"
            size="sm">
            <p class="mb-0">{{ __('admin.integrations.announcements.delete_webhook_confirm') }}</p>
            <input type="hidden" name="enabled" value="0">
            <input type="hidden" name="clear_webhook" value="1">
        </x-forms.modal>
    @endif
    <div id="admin-announcement-deliveries" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card mb-0">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.integrations.announcements.history') }}</h2>
            </div>
            <x-tables.controls :paginator="$deliveries" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="title">{{ __('common.labels.news') }}</th>
                            <th scope="col" data-table-sort="status">{{ __('common.labels.status') }}</th>
                            <th scope="col" data-table-sort="created_at">{{ __('common.labels.date') }}</th>
                            <th scope="col" class="text-end">{{ __('common.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($deliveries as $delivery)
                            <tr>
                                <td><a
                                        href="{{ route('admin.news.edit', $delivery->content_entry_id) }}">{{ $delivery->title }}</a>
                                </td>
                                <td><span
                                        class="badge text-bg-{{ $delivery->status === 'delivered' ? 'success' : (in_array($delivery->status, ['failed', 'uncertain'], true) ? 'warning' : 'secondary') }}">{{ __('admin.integrations.announcements.states.' . $delivery->status) }}</span>
                                    @if ($delivery->http_status)
                                        <small class="d-block text-secondary">HTTP {{ $delivery->http_status }}</small>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    {{ app(SiteDates::class)->format($delivery->created_at, 'd M Y · H:i T') }}
                                </td>
                                <td class="text-end">
                                    @if ($enabled && in_array($delivery->status, ['failed', 'uncertain', 'cancelled'], true))
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#announcement-retry-{{ $delivery->id }}">{{ __('admin.integrations.announcements.retry') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="4" :message="__('admin.integrations.announcements.empty')" :description="__('admin.integrations.announcements.empty_help')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $deliveries->links() }}
            </div>
        </section>
        @foreach ($deliveries as $delivery)
            @if ($enabled && in_array($delivery->status, ['failed', 'uncertain', 'cancelled'], true))
                <x-forms.modal :id="'announcement-retry-' . $delivery->id" :title="__('admin.integrations.announcements.retry')" :action="route('admin.content.announcements.retry', $delivery->id)" :submit="__('admin.integrations.announcements.retry')">
                    <p>{{ __('admin.integrations.announcements.retry_help') }}</p>
                    <p class="fw-semibold">{{ $delivery->title }}</p>
                    <div class="form-check mb-3">
                        <input class="form-check-input" id="confirm-{{ $delivery->id }}" type="checkbox" name="confirmed"
                            value="1" required>
                        <label class="form-check-label"
                            for="confirm-{{ $delivery->id }}">{{ __('admin.integrations.announcements.retry_confirm') }}</label>
                    </div>
                </x-forms.modal>
            @endif
        @endforeach
    </div>
@endsection
