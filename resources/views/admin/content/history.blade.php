@use('App\Kraft\Html')
@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('admin.' . $contentSection . '.history'))
@section('description', $entry->title)
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route($contentRoutes . '.edit', $entry) }}">
        {{ __('common.actions.edit') }}</a>
@endsection
@section('content')
    <div id="admin-content-history" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        <p class="text-body-secondary mb-0">{{ __('admin.' . $contentSection . '.history_help') }}</p>
        @forelse($revisions as $revision)
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">#{{ $revision->id }} · {{ __('site.content.action_' . $revision->action) }}</h2>
                    <time
                        class="float-end small text-body-secondary">{{ app(SiteDates::class)->format($revision->created_at, 'd/m/Y H:i:s T') }}</time>
                </div>
                <div class="card-body">
                    <h3 class="h5">{{ $revision->snapshot['title'] ?? '' }}</h3>
                    <p class="small text-body-secondary">{{ $revision->snapshot['slug'] ?? '' }} ·
                        {{ __('site.content.visibility_' . ($revision->snapshot['visibility'] ?? 'public')) }}</p>
                    <details>
                        <summary>{{ __('common.labels.content') }}</summary>
                        <div class="prose mt-3">
                            {!! Html::clean($revision->snapshot['body'] ?? '') !!}
                        </div>
                    </details>
                </div>
                @if (auth()->user()->hasPermission('content.restore'))
                    <div class="card-footer">
                        <form data-ajax="true" data-redirect-url="response" method="post"
                            action="{{ route($contentRoutes . '.restore', ['entry' => $entry, 'revision' => $revision->id]) }}"
                            data-confirm="{{ __('admin.' . $contentSection . '.restore_confirm') }}">
                            @csrf
                            <button class="btn btn-outline-primary" type="submit">{{ __('site.content.restore') }}</button>
                        </form>
                    </div>
                @endif
            </section>
        @empty
            <p class="text-body-secondary">{{ __('site.content.no_history') }}</p>
        @endforelse
        {{ $revisions->links() }}
    </div>
@endsection
