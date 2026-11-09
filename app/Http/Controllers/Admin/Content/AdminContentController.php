<?php

namespace App\Http\Controllers\Admin\Content;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Content\ContentComments;
use App\Models\Content\ContentComment;
use App\Models\Content\ContentCommentRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminContentController extends Controller
{
    public function comments(Request $request): View
    {
        $data = $request->validate([
            'state' => ['nullable', Rule::in(['pending', 'published', 'hidden'])],
        ]);
        $query = ContentComment::with(['author:id,username', 'entry:id,title,kind,slug'])->whereHas('entry');
        if (!empty($data['state'])) {
            $query->where('state', $data['state']);
        }

        return view('admin.content.comments.index', [
            'comments' => $query->latest('id')->paginate(30)->appends($data),
        ]);
    }

    public function moderate(Request $request, int $comment, ContentComments $comments): RedirectResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in(['pending', 'published', 'hidden'])],
            'body' => 'sometimes|required|string|max:10000',
        ]);
        DB::transaction(function () use ($request, $comment, $comments, $data) {
            $record = $comments->locked($comment);
            if (!$record->user_id) {
                throw new HttpException(409);
            }
            $record->recordRevision($request->user(), 'moderated');
            $record->update([
                'state' => $data['state'],
                'body' => isset($data['body']) ? $comments->clean($data['body']) : $record->body,
            ]);
        });
        Audit::record('content.comment_moderated', ['id' => $comment, 'state' => $data['state']]);

        return back()->with('status', __('site.content.comment_saved'));
    }

    public function commentHistory(int $comment): View
    {
        $record = ContentComment::query()->whereHas('entry')->findOrFail($comment);

        return view('admin.content.comments.history', [
            'comment' => $record,
            'revisions' => $record->revisions()->latest('id')->paginate(20),
        ]);
    }

    public function restoreComment(
        Request $request,
        int $comment,
        int $revision,
        ContentComments $comments,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $comment, $revision, $comments) {
            $record = $comments->locked($comment);
            if (!$record->user_id) {
                throw new HttpException(409);
            }
            /** @var ContentCommentRevision|null $snapshot */
            $snapshot = $record->revisions()->where('id', $revision)->first();
            if ($snapshot === null) {
                throw new NotFoundHttpException();
            }
            $record->recordRevision($request->user(), 'before_restore');
            $record->update(['body' => $comments->clean($snapshot->body), 'state' => $snapshot->state]);
        });
        Audit::record('content.comment_restored', ['id' => $comment, 'revision' => $revision]);

        return back()->with('status', __('site.content.restored'));
    }
}
