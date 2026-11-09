<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Kraft\System\TableQuery;
use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Kraft\Content\ContentComments;
use App\Models\Content\ContentComment;

class ContentController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $term = $data['q'] ?? '';
        $query = ContentEntry::published()
            ->visibleTo($request->user())
            ->where('kind', 'news')
            ->with(['author:id,username', 'cover', 'privateCover']);
        if ($term !== '') {
            TableQuery::whereContains($query, ['title', 'summary', 'body'], $term);
        }
        $news = $query->orderByDesc('is_pinned')->latest('published_at')->paginate(12)->withQueryString();

        return response()
            ->view('default.news.index', ['news' => $news, 'search' => $term])
            ->header('Cache-Control', 'private, no-store');
    }

    public function news(Request $request, string $slug): Response
    {
        return $this->show($request, 'news', $slug);
    }

    public function page(Request $request, string $slug): Response
    {
        return $this->show($request, 'page', $slug);
    }

    public function attachment(Request $request, int $entry, int $attachment): StreamedResponse
    {
        $content = ContentEntry::published()->visibleTo($request->user())->findOrFail($entry);
        $file = ContentAttachment::query()->where('content_entry_id', $content->id)->findOrFail($attachment);
        if (!Storage::disk('local')->exists($file->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function show(Request $request, string $kind, string $slug): Response
    {
        $entry = ContentEntry::published()
            ->visibleTo($request->user())
            ->where('kind', $kind)
            ->where('slug', $slug)
            ->with(['author:id,username', 'cover', 'privateCover', 'attachments'])
            ->firstOrFail();
        $comments = null;
        if ($entry->kind === 'news' && $entry->comments_enabled) {
            $comments = $entry
                ->comments()
                ->with('author:id,username')
                ->where(function (Builder $comments) use ($request) {
                    $comments->where('state', 'published');
                    if ($request->user()) {
                        $comments->orWhere(
                            fn (Builder $own) => $own->where('user_id', $request->user()->id)->where('state', 'pending'),
                        );
                    }
                })
                ->orderBy('created_at')
                ->paginate(20)
                ->withQueryString();
        }
        $reactionCount = 0;
        $reacted = false;
        if ($entry->kind === 'news' && $entry->reactions_enabled) {
            $reactions = $entry->reactions();
            $reactionCount = (clone $reactions)->count();
            $reacted = $request->user() && (clone $reactions)->where('user_id', $request->user()->id)->exists();
        }
        $commentPolicy = app(ContentComments::class);
        $commentControls = [];
        foreach ($comments ?? [] as $comment) {
            /** @var ContentComment $comment */
            $canEdit = $commentPolicy->canEdit($comment, $request->user());
            $canDelete = $commentPolicy->canDelete($comment, $request->user());
            $commentControls[$comment->id] = [
                'canEdit' => $canEdit,
                'canDelete' => $canDelete,
                'hasActions' => $canEdit || $canDelete,
                'isEditing' => $canEdit && $request->integer('edit') === $comment->id,
            ];
        }
        $contentControls = [
            'showReactions' => $entry->kind === 'news' && $entry->reactions_enabled,
            'canReact' => $request->user() !== null,
            'showComments' => $entry->kind === 'news' && $entry->comments_enabled,
            'canComment' => $request->user()?->hasVerifiedEmail() ?? false,
            'requiresCommentLogin' => $request->user() === null,
        ];

        return response()
            ->view(
                'default.content.show',
                compact('entry', 'comments', 'reactionCount', 'reacted', 'contentControls', 'commentControls') + [
                    'seoEntry' => $entry,
                ],
            )
            ->header('Cache-Control', 'private, no-store');
    }
}
