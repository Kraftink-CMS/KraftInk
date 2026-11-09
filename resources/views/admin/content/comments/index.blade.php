@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('site.content.moderation'))
@section('description', __('site.content.moderation_help'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.trash.index', ['source' => 'comments']) }}">{{ __('trash.title') }}</a>
@endsection
@section('content')
    <div id="admin-comments-list" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-body">
                <form method="get" class="row g-2">
                    <div class="col-md-4">
                        <label class="visually-hidden" for="comment-state">{{ __('common.labels.status') }}</label>
                        <select class="form-select" id="comment-state" name="state">
                            <option value="">{{ __('site.content.all_states') }}</option>
                            @foreach (['pending', 'published', 'hidden'] as $state)
                                <option value="{{ $state }}" @selected(request('state') === $state)>{{ __('site.content.state_' . $state) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-outline-primary" type="submit">{{ __('site.content.filter') }}</button>
                    </div>
                </form>
            </div>
        </section>
        @forelse($comments as $comment)
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ $comment->entry?->title ?? '—' }}</h2>
                    <span
                        class="badge text-bg-secondary float-end">{{ __('site.content.state_' . ($comment->deleted_at ? 'deleted' : $comment->state)) }}</span>
                </div>
                <div class="card-body">
                    <p class="small text-body-secondary">{{ $comment->author?->username ?? __('site.content.former_member') }} ·
                        {{ app(SiteDates::class)->format($comment->created_at, 'd/m/Y H:i T') }}</p>
                    <div class="prose">
                        {!! $comment->body !!}
                    </div>
                    <div class="d-flex flex-wrap gap-3 mt-3">
                        <a href="{{ route('admin.content.comments.history', $comment->id) }}">{{ __('site.content.history') }}</a>
                        @if ($comment->user_id)
                            <a
                                href="{{ route('admin.content.comments.index', ['state' => request('state'), 'edit' => $comment->id]) }}">{{ __('common.actions.edit') }}</a>
                        @endif
                    </div>
                    @if ($comment->user_id)
                        <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.content.comments.moderate', $comment->id) }}"
                            class="mt-3">
                            @csrf
                            @if (request()->integer('edit') === $comment->id)
                                <label class="form-label"
                                    for="moderation-body-{{ $comment->id }}">{{ __('common.labels.content') }}</label>
                                <textarea class="form-control mb-3" data-editor rows="5" name="body" id="moderation-body-{{ $comment->id }}"
                                    required>{{ $comment->body }}</textarea>
                            @endif
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label"
                                        for="state-{{ $comment->id }}">{{ __('common.labels.status') }}</label>
                                    <select class="form-select" id="state-{{ $comment->id }}" name="state">
                                        @foreach (['pending', 'published', 'hidden'] as $state)
                                            <option value="{{ $state }}" @selected($comment->state === $state)>
                                                {{ __('site.content.state_' . $state) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button class="btn btn-primary" type="submit">{{ __('site.content.moderate') }}</button>
                                </div>
                            </div>
                        </form>
                    @endif
                </div>
            </section>
        @empty
            <p class="text-body-secondary">{{ __('site.content.no_comments') }}</p>
        @endforelse
        {{ $comments->links() }}
    </div>
@endsection
