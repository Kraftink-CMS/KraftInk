@use('App\Kraft\SiteDates')
@use('Illuminate\Support\Str')
@extends('admin.layouts.layout')
@section('title', __('site.community.notices_title'))
@section('description', __('site.community.notices_description'))
@section('actions')
    <a class="btn btn-primary" href="{{ route('admin.notifications.create') }}">
        {{ __('site.community.create_notice') }}</a>
@endsection
@section('content')
    <div id="admin-notices-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <x-tables.controls :paginator="$notices" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th data-table-sort="title">{{ __('common.labels.title') }}</th>
                            <th>{{ __('site.community.notice_audience') }}</th>
                            <th data-table-sort="published_at">{{ __('site.community.notice_publication') }}</th>
                            <th data-table-sort="expires_at">{{ __('site.community.notice_expiration') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($notices as $notice)
                            <tr>
                                <td><strong>{{ $notice->title }}</strong>
                                    <p class="small text-body-secondary mb-0">
                                        {{ Str::limit(strip_tags($notice->body), 120) }}</p>
                                </td>
                                <td>{{ __('site.community.audience_' . $notice->audience) }}</td>
                                <td>{{ app(SiteDates::class)->format($notice->published_at, 'd/m/Y H:i T') }}
                                    @if ($notice->published_at->isFuture())
                                        <span class="badge text-bg-secondary d-block">{{ __('common.statuses.scheduled') }}</span>
                                    @endif
                                </td>
                                <td>{{ app(SiteDates::class)->format($notice->expires_at, 'd/m/Y H:i T') ?? '—' }}
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                            data-bs-toggle="dropdown" aria-expanded="false">{{ __('common.labels.actions') }}</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <button class="dropdown-item" type="button" data-bs-toggle="modal"
                                                    data-bs-target="#notice-preview-{{ $notice->id }}">{{ __('admin.servers.preview') }}</button>
                                            </li>
                                            <li>
                                                <button class="dropdown-item text-danger" type="button" data-bs-toggle="modal"
                                                    data-bs-target="#notice-delete-{{ $notice->id }}">{{ __('common.actions.delete') }}</button>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="5" :message="__('site.community.no_notices')" :description="__('site.community.publish_first_notice')" :action="__('site.community.create_notice')"
                                :href="route('admin.notifications.create')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $notices->links() }}
            </div>
        </section>
        @foreach ($notices as $notice)
            <div class="modal fade" id="notice-preview-{{ $notice->id }}" tabindex="-1"
                aria-labelledby="notice-title-{{ $notice->id }}" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="notice-title-{{ $notice->id }}">{{ $notice->title }}</h2>
                            <button class="btn-close" type="button" data-bs-dismiss="modal"
                                aria-label="{{ __('admin.servers.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="prose">
                                {!! $notice->body !!}
                            </div>
                            @if ($notice->url)
                                <a class="btn btn-outline-primary mt-3"
                                    href="{{ $notice->url }}">{{ __('site.community.notice_url') }}</a>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" type="button"
                                data-bs-dismiss="modal">{{ __('admin.servers.close') }}</button>
                        </div>
                    </div>
                </div>
            </div>
            <x-forms.modal :id="'notice-delete-' . $notice->id" :title="__('common.actions.delete')" :action="route('admin.notifications.destroy', $notice)" method="DELETE" :submit="__('common.actions.delete')"
                size="sm">
                <p>{{ __('site.community.confirm_delete_notice') }}</p>
                <strong>{{ $notice->title }}</strong>
            </x-forms.modal>
        @endforeach
    </div>
@endsection
