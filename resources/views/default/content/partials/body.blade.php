@use('App\Kraft\SiteDates')
<x-extensions.slot name="site.content.before" :context="['entry' => $entry]" />
<article class="article">
    <header class="article-header mb-4 pb-3 border-bottom">
        <a class="d-inline-block mb-3"
            href="{{ $entry->kind === 'news' ? route('site.news.index') : url('/') }}">{{ $entry->kind === 'news' ? __('site.content.news') : __('site.home.back_to_home') }}</a>
        <h1>{{ $entry->title }}</h1>
        @if ($entry->kind === 'news')
            <p class="small text-body-secondary mb-0">
                {{ __('site.content.by_author', ['name' => $entry->author?->username ?? __('site.content.former_member')]) }} · <time
                    datetime="{{ $entry->published_at?->toAtomString() }}">{{ app(SiteDates::class)->format($entry->published_at, 'd F Y') }}</time>
            </p>
        @endif
    </header>
    @php
        $cover = $entry->coverAsset();
    @endphp
    @if ($cover)
        <img class="img-fluid rounded mb-4" src="{{ $entry->coverUrl() }}"
            alt="{{ $entry->cover_image_id ? $entry->title : ($cover->displayText('alt') ?: $entry->title) }}" width="{{ $cover->width }}"
            height="{{ $cover->height }}">
    @endif
    @if ($entry->summary)
        <p class="lead">{{ $entry->summary }}</p>
    @endif
    <div class="prose">
        {!! $entry->body !!}
    </div>
    @if ($entry->attachments->isNotEmpty())
        <section class="border-top mt-4 pt-4">
            <h2 class="h5">{{ __('site.content.attachments') }}</h2>
            <ul class="list-group list-group-flush">
                @foreach ($entry->attachments as $file)
                    <li class="list-group-item px-0 d-flex flex-wrap justify-content-between gap-2">
                        <span>{{ $file->original_name }} <small
                                class="text-body-secondary">({{ number_format($file->size / 1024, 0) }}
                                KB)</small></span><a
                            href="{{ route('site.content.attachments.download', ['entry' => $entry->id, 'attachment' => $file->id]) }}">{{ __('site.content.download') }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    @if ($contentControls['showReactions'])
        <section class="border-top mt-4 pt-4 d-flex flex-wrap gap-3 align-items-center" id="reactions">
            <span>{{ __('site.content.reactions', ['count' => $reactionCount]) }}</span>
            @if ($contentControls['canReact'])
                <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
                    action="{{ route($reacted ? 'site.news.reactions.destroy' : 'site.news.reactions.store', $entry->id) }}">
                    @csrf
                    @if ($reacted)
                        @method('DELETE')
                    @endif
                    <button class="btn btn-outline-primary btn-sm" type="submit"
                        aria-pressed="{{ $reacted ? 'true' : 'false' }}">
                        {{ $reacted ? __('site.content.unlike') : __('site.content.like') }}</button>
                </form>
            @endif
        </section>
    @endif
    @if ($contentControls['showComments'])
        <section class="border-top mt-4 pt-4" id="comments">
            <h2 class="h4 mb-4">{{ __('site.content.comments') }}</h2>
            <div id="content-comments-list" data-ajax-pagination tabindex="-1" class="vstack gap-4">
                @forelse($comments as $comment)
                    <article class="card mb-3" id="comment-{{ $comment->id }}">
                        <div class="card-body">
                            <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                                <strong>{{ $comment->author?->username ?? __('site.content.former_member') }}</strong><time
                                    class="small text-body-secondary"
                                    datetime="{{ $comment->created_at->toAtomString() }}">{{ app(SiteDates::class)->format($comment->created_at, 'd F Y H:i T') }}</time>
                            </div>
                            @if ($comment->state === 'pending')
                                <p class="alert alert-warning">{{ __('site.content.pending_notice') }}</p>
                            @endif
                            <div class="prose">
                                {!! $comment->body !!}
                            </div>
                            @if ($commentControls[$comment->id]['hasActions'])
                                <div class="d-flex gap-3 flex-wrap mt-3">
                                    @if ($commentControls[$comment->id]['canEdit'])
                                        <a class="small"
                                            href="{{ $entry->url() . '?edit=' . $comment->id . '#comment-' . $comment->id }}">{{ __('common.actions.edit') }}</a>
                                    @endif
                                    @if ($commentControls[$comment->id]['canDelete'])
                                        <form data-ajax="true" data-redirect-url="response" method="post"
                                            action="{{ route('site.news.comments.destroy', ['entry' => $entry->id, 'comment' => $comment->id]) }}"
                                            data-confirm="{{ __('site.content.delete_comment') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-link btn-sm p-0 text-danger"
                                                type="submit">{{ __('common.actions.delete') }}</button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                            @if ($commentControls[$comment->id]['isEditing'])
                                <form data-ajax="true" data-redirect-url="response" method="post"
                                    action="{{ route('site.news.comments.update', ['entry' => $entry->id, 'comment' => $comment->id]) }}"
                                    class="mt-3">
                                    @csrf
                                    <label class="form-label"
                                        for="comment-edit-{{ $comment->id }}">{{ __('site.content.edit_comment') }}</label>
                                    <textarea class="form-control" id="comment-edit-{{ $comment->id }}" name="body" data-editor rows="5" required
                                        maxlength="10000">{{ $comment->body }}</textarea>
                                    <button class="btn btn-primary btn-sm mt-3"
                                        type="submit">{{ __('common.actions.save') }}</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="text-body-secondary">{{ __('site.content.no_comments') }}</p>
                @endforelse
                {{ $comments->links() }}
            </div>
            @if ($contentControls['canComment'])
                <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('site.news.comments.store', $entry->id) }}" class="mt-4">
                    @csrf
                    <label class="form-label" for="comment-body">{{ __('site.content.write_comment') }}</label>
                    <textarea class="form-control" id="comment-body" name="body" data-editor rows="6" required maxlength="10000">{{ old('body') }}</textarea>
                    <button class="btn btn-primary mt-3" type="submit">{{ __('site.content.send_comment') }}</button>
                </form>
            @elseif ($contentControls['requiresCommentLogin'])
                <p class="mt-4"><a href="{{ route('login') }}">{{ __('site.content.login_to_comment') }}</a></p>
            @else
                <p class="mt-4"><a
                        href="{{ route('verification.notice') }}">{{ __('site.content.verify_to_comment') }}</a></p>
            @endif
        </section>
    @endif
</article>
<x-extensions.slot name="site.content.after" :context="['entry' => $entry]" />
