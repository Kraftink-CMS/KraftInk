@use('App\Kraft\Html')
@use('App\Kraft\SiteDates')
@extends('admin.layouts.layout')
@section('title', __('site.content.history'))
@section('description', __('site.content.comments'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.content.comments.index') }}">
        {{ __('site.content.moderation') }}</a>
@endsection
@section('content')
    <div id="admin-comment-history" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        @forelse($revisions as $revision)
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">#{{ $revision->id }} · {{ __('site.content.action_' . $revision->action) }}</h2>
                </div>
                <div class="card-body">
                    <p class="small text-body-secondary">{{ app(SiteDates::class)->format($revision->created_at) }} ·
                        {{ __('site.content.state_' . $revision->state) }}</p>
                    <div class="prose">
                        {!! Html::clean($revision->body) !!}
                    </div>
                </div>
                @if ($comment->user_id && auth()->user()->hasPermission('content.restore'))
                    <div class="card-footer">
                        <form data-ajax="true" data-redirect-url="response" method="post"
                            action="{{ route('admin.content.comments.restore', ['comment' => $comment->id, 'revision' => $revision->id]) }}"
                            data-confirm="{{ __('site.content.restore_confirm') }}">
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
