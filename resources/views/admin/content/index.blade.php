@extends('admin.layouts.layout')
@section('title', __('admin.' . $contentSection . '.title'))
@section('description', __('admin.' . $contentSection . '.description'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.trash.index', ['source' => $contentSection]) }}">{{ __('trash.title') }}</a>
    <a class="btn btn-primary" href="{{ route($contentRoutes . '.create') }}">
        {{ __('admin.' . $contentSection . '.create') }}</a>
@endsection
@section('content')
    <div id="admin-content-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-body">
                <x-tables.controls :paginator="$entries" class="mb-3" />
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="content-search">{{ __('common.labels.title') }}</label>
                        <input class="form-control" type="search" id="content-search" name="q" value="{{ request('q') }}"
                            maxlength="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="content-status">{{ __('common.labels.publication') }}</label>
                        <select class="form-select" id="content-status" name="status">
                            <option value="">{{ __('admin.' . $contentSection . '.all') }}</option>
                            @foreach (['draft', 'scheduled', 'published'] as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('common.statuses.' . $status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <noscript>
                        <div class="col-auto">
                            <button class="btn btn-outline-primary" type="submit">{{ __('site.content.filter') }}</button>
                        </div>
                    </noscript>
                </form>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th data-table-sort="title">{{ __('common.labels.title') }}</th>
                            <th data-table-sort="locale">{{ __('common.labels.language') }}</th>
                            <th data-table-sort="visibility">{{ __('site.content.visibility') }}</th>
                            <th data-table-sort="published_at">{{ __('common.labels.publication') }}</th>
                            <th>{{ __('common.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($entries as $entry)
                            <tr>
                                <td><strong>{{ $entry->title }}</strong><small
                                        class="d-block text-body-secondary">{{ $entry->url() }}</small></td>
                                <td>{{ strtoupper($entry->locale) }}</td>
                                <td>{{ __('site.content.visibility_' . $entry->visibility) }}</td>
                                <td>
                                    @if (!$entry->published_at)
                                        <span class="badge text-bg-secondary">{{ __('common.statuses.draft') }}</span>
                                    @elseif($entry->published_at->isFuture())
                                        <span class="badge text-bg-secondary">{{ __('common.statuses.scheduled') }}</span>
                                    @else
                                        <span class="badge text-bg-success">{{ __('common.statuses.published') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-outline-primary btn-sm"
                                            href="{{ route($contentRoutes . '.edit', $entry) }}">{{ __('common.actions.edit') }}</a>
                                        @if ($entry->revisions_exists)
                                            <a class="btn btn-outline-secondary btn-sm"
                                                href="{{ route($contentRoutes . '.history', $entry) }}">{{ __('site.content.history') }}</a>
                                        @endif
                                        <form data-ajax="true" data-redirect-url="response" method="post"
                                            action="{{ route($contentRoutes . '.destroy', $entry) }}"
                                            data-confirm="{{ __('admin.' . $contentSection . '.delete_confirm') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm"
                                                type="submit">{{ __('common.actions.delete') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="5" :message="__('admin.' . $contentSection . '.empty')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $entries->links() }}
            </div>
        </section>
    </div>
@endsection
