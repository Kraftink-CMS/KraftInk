@use('App\Kraft\SiteDates')
@use('Carbon\CarbonImmutable')
@use('Illuminate\Support\Str')
@extends('default.layouts.layout')
@section('title', __('site.account.privacy.title'))
@section('content')
    <header class="page-heading">
        <p class="eyebrow small text-body-secondary">{{ __('site.auth.account.heading') }}</p>
        <h1>{{ __('site.account.privacy.title') }}</h1>
        <p>{{ __('site.account.privacy.description') }}</p>
    </header>
    <div class="account-grid mb-4">
        @if ($hasRetainedAvatar)
            <section class="panel card card-body p-4">
                <h2 class="mb-3">{{ __('admin.settings.avatars.stored_image') }}</h2>
                <p class="text-body-secondary mb-4">{{ __('admin.settings.avatars.stored_image_retained') }}</p>
                <form data-ajax="true" data-redirect-url="response" method="post"
                    action="{{ route('account.avatar.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-outline-secondary" type="submit">{{ __('admin.settings.avatars.remove_stored_image') }}</button>
                </form>
            </section>
        @endif
        <section class="panel card card-body p-4">
            <h2 class="mb-3">{{ __('site.account.privacy.export_title') }}</h2>
            <p class="text-muted mb-4">{{ __('site.account.privacy.export_help') }}</p>
            <form data-ajax="true" method="post" action="{{ route('account.privacy.export') }}">
                @csrf
                @include('partials.forms.sensitive-fields', ['formKey' => 'export'])
                <button class="button btn btn-primary" type="submit">{{ __('site.account.privacy.download_export') }}</button>
            </form>
        </section>
        <section class="panel card card-body p-4">
            <h2 class="mb-3">{{ __('site.account.privacy.sessions_title') }}</h2>
            <p class="text-muted mb-4">{{ __('site.account.privacy.sessions_help') }}</p>
            <form data-ajax="true" data-redirect-url="response" method="post"
                action="{{ route('account.sessions.revoke') }}">
                @csrf
                <button class="button button-outline btn btn-outline-secondary"
                    type="submit">{{ __('site.account.privacy.revoke_others') }}</button>
            </form>
        </section>
    </div>
    @if ($capabilities->canListSessions)
        <div id="account-session-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
            <section class="panel card mb-4">
                <div class="card-body p-4">
                    <h2 class="mb-3">{{ __('site.account.privacy.devices') }}</h2>
                    <x-tables.controls :paginator="$sessions" class="mb-3" />
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="text-nowrap">
                                <tr>
                                    <th data-table-sort="user_agent">{{ __('site.account.privacy.device') }}</th>
                                    <th data-table-sort="ip_address">{{ __('site.account.privacy.ip_address') }}</th>
                                    <th data-table-sort="last_activity">{{ __('site.account.privacy.last_activity') }}</th>
                                    <th>{{ __('common.labels.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sessions as $session)
                                    <tr>
                                        <td>{{ Str::limit($session->user_agent ?: __('site.account.privacy.unknown_device'), 150) }}
                                        </td>
                                        <td>{{ $session->ip_address }}</td>
                                        <td>{{ app(SiteDates::class)->format(CarbonImmutable::createFromTimestampUTC($session->last_activity)) }}
                                        </td>
                                        <td>
                                            @if ($session->id === session()->getId())
                                                {{ __('site.account.privacy.current_device') }}
                                            @else
                                                <details>
                                                    <summary>{{ __('site.account.privacy.revoke_device') }}</summary>
                                                    <form data-ajax="true" data-redirect-url="response" method="post"
                                                        action="{{ route('account.sessions.destroy', $session->id) }}"
                                                        class="mt-3">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button
                                                            class="button button-small button-outline btn btn-outline-secondary btn-sm"
                                                            type="submit">{{ __('site.account.privacy.revoke_device') }}</button>
                                                    </form>
                                                </details>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    {{ $sessions->links() }}
                </div>
            </section>
        </div>

    @endif
    @if ($capabilities->canDeleteAccount)
        <section class="panel card card-body p-4">
            <h2 class="mb-3">{{ __('site.account.privacy.delete_title') }}</h2>
            <p class="text-muted mb-4">{{ __('site.account.privacy.delete_help') }}</p>
            <form data-ajax="true" data-redirect-url="response" method="post"
                action="{{ route('account.privacy.destroy') }}" data-confirm="{{ __('site.account.privacy.delete_confirm') }}">
                @csrf
                @method('DELETE')
                @include('partials.forms.sensitive-fields', ['formKey' => 'delete'])
                <label class="check-label mb-4 d-flex align-items-start gap-2 form-label">
                    <input class="form-check-input" type="checkbox" name="confirmation" value="1" required>
                    <span>{{ __('site.account.privacy.confirmation') }}</span></label>
                <button class="button button-danger btn btn-danger"
                    type="submit">{{ __('site.account.privacy.close_account') }}</button>
            </form>
        </section>
    @endif
@endsection
