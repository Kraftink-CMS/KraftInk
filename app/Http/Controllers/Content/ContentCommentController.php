<?php

namespace App\Http\Controllers\Content;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Content\ContentComments;
use App\Models\Content\ContentEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContentCommentController extends Controller
{
    public function store(Request $request, int $entry, ContentComments $comments): RedirectResponse
    {
        $content = $this->content($request, $entry);
        if (!$content->comments_enabled) {
            throw new HttpException(403);
        }
        $data = $request->validate(['body' => 'required|string|max:10000']);
        $body = $comments->clean($data['body']);
        DB::transaction(function () use ($content, $request, $body) {
            $current = $this->content($request, $content->id, forUpdate: true);
            if (!$current->comments_enabled) {
                throw new HttpException(403);
            }
            $current->comments()->create([
                'user_id' => $request->user()->id,
                'body' => $body,
                'state' => $current->comments_moderated && !$request->user()->hasPermission('content.comments.manage')
                        ? 'pending'
                        : 'published',
            ]);
        });
        Audit::record('content.comment_created', ['entry' => $content->id]);

        return redirect($content->url().'#comments')->with('status', __('site.content.comment_saved'));
    }

    public function update(Request $request, int $entry, int $comment, ContentComments $comments): RedirectResponse
    {
        $content = $this->content($request, $entry);
        if (!$content->comments_enabled) {
            throw new HttpException(403);
        }
        $data = $request->validate(['body' => 'required|string|max:10000']);
        $body = $comments->clean($data['body']);
        DB::transaction(function () use ($content, $request, $comment, $body, $comments) {
            $content = $this->content($request, $content->id, forUpdate: true);
            if (!$content->comments_enabled) {
                throw new HttpException(403);
            }
            $item = $content->comments()->lockForUpdate()->findOrFail($comment);
            $moderator = $request->user()->hasPermission('content.comments.manage');
            if (!$comments->canEdit($item, $request->user())) {
                throw new HttpException(403);
            }
            $item->recordRevision($request->user(), 'edited');
            $item->update([
                'body' => $body,
                'state' => !$moderator && $content->comments_moderated ? 'pending' : $item->state,
            ]);
        });
        Audit::record('content.comment_edited', ['id' => $comment]);

        return redirect($content->url().'#comments')->with('status', __('site.content.comment_saved'));
    }

    public function destroy(Request $request, int $entry, int $comment, ContentComments $comments): RedirectResponse
    {
        $content = $this->content($request, $entry);
        DB::transaction(function () use ($content, $request, $comment, $comments) {
            $content = $this->content($request, $content->id, forUpdate: true);
            $item = $content->comments()->lockForUpdate()->findOrFail($comment);
            if (!$comments->canDelete($item, $request->user())) {
                throw new HttpException(403);
            }
            $item->recordRevision($request->user(), 'deleted');
            $item->delete();
        });
        Audit::record('content.comment_deleted', ['id' => $comment]);

        return redirect($content->url().'#comments')->with('status', __('site.content.comment_deleted'));
    }

    public function reaction(Request $request, int $entry): RedirectResponse
    {
        $content = $this->content($request, $entry);
        if (!$content->reactions_enabled) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($content, $request) {
            $current = $this->content($request, $content->id, forUpdate: true);
            if (!$current->reactions_enabled) {
                throw new HttpException(403);
            }
            $query = $current->reactions()->where('user_id', $request->user()->id);
            if ($request->isMethod('delete')) {
                $query->delete();
            } else {
                $current->reactions()->firstOrCreate(['user_id' => $request->user()->id]);
            }
        });

        return redirect($content->url().'#reactions');
    }

    private function content(Request $request, int $id, bool $forUpdate = false): ContentEntry
    {
        return ContentEntry::published()
            ->visibleTo($request->user())
            ->where('kind', 'news')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->findOrFail($id);
    }
}
