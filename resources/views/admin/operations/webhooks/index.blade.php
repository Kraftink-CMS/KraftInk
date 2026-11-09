@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('admin.operations.webhooks'))
@section('description', __('admin.operations.webhooks_description'))
@section('content')
    <form data-ajax="true" data-redirect-url="response" class="card" method="post"
        action="{{ route('admin.operations.webhooks.store') }}">
        <div class="card-body">
            @csrf
            <input type="hidden" name="enabled" value="0">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" id="enabled" type="checkbox" name="enabled" value="1"
                    @checked(old('enabled', $enabled))>
                <label class="form-check-label" for="enabled">{{ __('admin.operations.webhook_enabled') }}</label>
            </div>
            @if ($host)
                <p>{{ __('admin.operations.webhook_current_host') }} <strong>{{ $host }}</strong></p>
            @endif
            <div class="mb-3">
                <label class="form-label" for="webhook_url">{{ __('admin.operations.webhook_url') }}</label>
                <input class="form-control" id="webhook_url" type="url" name="webhook_url" maxlength="2000"
                    autocomplete="off" data-webhook-url>
                <div class="form-text">
                    {{ __('admin.operations.webhook_url_help') }}
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="webhook_secret">{{ __('admin.operations.webhook_secret') }}</label>
                <div class="input-group">
                    <input class="form-control" id="webhook_secret" type="password" name="webhook_secret" minlength="32"
                        maxlength="512" autocomplete="new-password" data-webhook-secret>
                    <button class="btn btn-outline-secondary" type="button"
                        data-webhook-generate>{{ __('admin.operations.generate_secret') }}</button>
                    <button class="btn btn-outline-secondary" type="button" data-webhook-copy
                        data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
                </div>
                <div class="form-text">
                    {{ __('admin.operations.webhook_secret_help') }}
                    {{ $hasSecret ? __('admin.operations.webhook_secret_set') : __('admin.operations.webhook_secret_missing') }}
                </div>
            </div>
            <fieldset class="d-none" data-webhook-confirmation hidden disabled>
                @include('partials.forms.confirm-action', ['confirmationId' => 'audit-webhook'])
            </fieldset>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
        </div>
    </form>
    @if ($enabled)
        <form data-ajax="true" class="card" method="post" action="{{ route('admin.operations.webhooks.test') }}">
            @csrf
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-outline-primary" type="submit">{{ __('admin.operations.webhook_test') }}</button>
            </div>
        </form>
    @endif
    <div class="alert alert-info">
        {{ __('admin.operations.webhook_delivery_help') }}
    </div>
    <div id="admin-webhook-deliveries" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <x-tables.controls :paginator="$deliveries" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="created_at">{{ __('common.labels.date') }}</th>
                            <th scope="col">{{ __('admin.operations.webhook_event') }}</th>
                            <th scope="col" data-table-sort="status">{{ __('common.labels.status') }}</th>
                            <th scope="col" data-table-sort="attempts">{{ __('admin.operations.webhook_attempts') }}
                            </th>
                            <th scope="col">{{ __('common.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($deliveries as $delivery)
                            <tr>
                                <td>{{ app(SiteDates::class)->format($delivery->created_at) }}</td>
                                <td>
                                    @if ($delivery->audit_id)
                                        <a
                                            href="{{ route('admin.operations.audit.show', $delivery->audit_id) }}">#{{ $delivery->audit_id }}</a>
                                    @endif
                                    <br>
                                    <small class="text-break">{{ $delivery->id }}</small>
                                </td>
                                <td>{{ __('admin.operations.delivery_states.' . $delivery->status) }}
                                    @if ($delivery->http_status)
                                        <br><small>HTTP {{ $delivery->http_status }}</small>
                                    @endif
                                </td>
                                <td>{{ $delivery->attempts }} / 8</td>
                                <td>
                                    @if ($delivery->status === 'failed')
                                        <form data-ajax="true" data-redirect-url="response" method="post"
                                            action="{{ route('admin.operations.webhooks.retry', $delivery->id) }}">
                                            @csrf
                                            <button class="btn btn-outline-primary btn-sm"
                                                type="submit">{{ __('admin.operations.webhook_retry') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">{{ __('admin.operations.no_deliveries') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $deliveries->links() }}
            </div>
        </section>
    </div>
@endsection
