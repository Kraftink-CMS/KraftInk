@use('App\Kraft\SiteDates')
@use('Illuminate\Support\Str')
@extends('admin.layouts.layout')
@section('title', __('trash.title'))
@section('description', __('trash.description'))
@section('actions')
    @if ($purgeableCount > 0)
        <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#trash-empty">
            {{ __('trash.empty_trash') }}
        </button>
    @endif
@endsection
@section('content')
    <div id="admin-trash-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-body">
                <x-tables.controls :paginator="$items" class="mb-3" />
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="trash-search">{{ __('common.labels.title') }}</label>
                        <input class="form-control" type="search" id="trash-search" name="q"
                            value="{{ request('q') }}" maxlength="100">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="trash-source">{{ __('trash.source') }}</label>
                        <select class="form-select" id="trash-source" name="source">
                            <option value="">{{ __('trash.all_sources') }}</option>
                            @foreach ($sources as $key => $source)
                                <option value="{{ $key }}" @selected(request('source') === $key)>{{ __($source->label) }}
                                </option>
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
                            <th scope="col" data-table-sort="title">{{ __('common.labels.title') }}</th>
                            <th scope="col" data-table-sort="source">{{ __('trash.source') }}</th>
                            <th scope="col" data-table-sort="deleted_at">{{ __('trash.deleted_at') }}</th>
                            <th scope="col" class="text-end">{{ __('common.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td class="trash-item-title">
                                    {{ Str::limit(strip_tags($item->title ?? ''), 160) ?: __('trash.untitled', ['id' => $item->id]) }}
                                    @if ($item->restore_reason !== null)
                                        <div class="small text-body-secondary">{{ __($item->restore_reason) }}</div>
                                    @endif
                                </td>
                                <td>{{ __($sources[$item->source]->label) }}</td>
                                <td>{{ app(SiteDates::class)->format($item->deleted_at, 'd/m/Y H:i') }}</td>
                                <td class="text-end">
                                    @if ($sources[$item->source]->canRestoreItem(auth()->user(), $item) || $sources[$item->source]->canPurge(auth()->user()))
                                        <div class="d-flex flex-wrap justify-content-end gap-2">
                                            @if ($sources[$item->source]->canRestoreItem(auth()->user(), $item))
                                                <form data-ajax="true" data-redirect-url="response" method="post"
                                                    action="{{ route('admin.trash.restore', ['source' => $item->source, 'item' => $item->id]) }}">
                                                    @csrf
                                                    <button class="btn btn-outline-primary btn-sm"
                                                        type="submit">{{ __('trash.restore') }}</button>
                                                </form>
                                            @endif
                                            @if ($sources[$item->source]->canPurge(auth()->user()))
                                                <button class="btn btn-outline-danger btn-sm" type="button"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#trash-purge-{{ md5($item->source . ':' . $item->id) }}">
                                                    {{ __('trash.purge') }}
                                                </button>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-body-secondary">{{ __('trash.read_only') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="4" :message="__('trash.empty')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $items->links() }}
            </div>
        </section>
        @foreach ($items as $item)
            @if ($sources[$item->source]->canPurge(auth()->user()))
                @php
                    $purgeModalId = 'trash-purge-' . md5($item->source . ':' . $item->id);
                @endphp
                <x-forms.modal :id="$purgeModalId" :title="__('trash.purge')" method="DELETE" size="lg" :action="route('admin.trash.purge', ['source' => $item->source, 'item' => $item->id])"
                    :submit="__('trash.purge')">
                    <p>{{ __('trash.purge_help', ['title' => Str::limit(strip_tags($item->title ?? ''), 160) ?: __('trash.untitled', ['id' => $item->id])]) }}
                    </p>
                    <p class="small text-body-secondary">{{ __('trash.shared_files_help') }}</p>
                    @include('partials.forms.confirm-action', ['confirmationId' => $purgeModalId])
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="confirmed" value="1"
                            id="{{ $purgeModalId }}-confirmed" required>
                        <label class="form-check-label"
                            for="{{ $purgeModalId }}-confirmed">{{ __('trash.confirm_purge') }}</label>
                    </div>
                </x-forms.modal>
            @endif
        @endforeach
    </div>
    @if ($purgeableCount > 0)
        <x-forms.modal id="trash-empty" :title="__('trash.empty_trash')" :action="route('admin.trash.empty')" method="DELETE" :submit="__('trash.empty_trash')">
            <p>{{ __('trash.empty_help', ['count' => $purgeableCount]) }}</p>
            <p>{{ __('trash.empty_scope') }}</p>
            <p class="small text-body-secondary">{{ __('trash.shared_files_help') }}</p>
            @include('partials.forms.confirm-action', ['confirmationId' => 'trash-empty'])
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="confirmed" value="1" id="trash-empty-confirmed"
                    required>
                <label class="form-check-label" for="trash-empty-confirmed">{{ __('trash.confirm_empty') }}</label>
            </div>
        </x-forms.modal>
    @endif
@endsection
