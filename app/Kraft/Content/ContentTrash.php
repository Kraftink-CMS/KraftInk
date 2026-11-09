<?php

namespace App\Kraft\Content;

use App\Kraft\Audit;
use App\Kraft\Media\FileDeletionQueue;
use App\Models\Content\ContentAnnouncement;
use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentEntry;
use App\Models\Content\ContentComment;
use App\Models\Content\ContentImage;
use App\Kraft\Trash\TrashRegistry;
use App\Kraft\Trash\TrashSource;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ContentTrash
{
    public function register(TrashRegistry $registry): void
    {
        foreach (['news' => 'news', 'pages' => 'page'] as $key => $kind) {
            $registry->register(
                new TrashSource(
                    $key,
                    'admin.'.$key.'.title',
                    ['content.manage'],
                    ['content.restore'],
                    fn (User $user) => ContentEntry::withTrashed()
                        ->where('kind', $kind)
                        ->whereNotNull('deleted_at')
                        ->select(['id', 'title', 'deleted_at'])
                        ->selectRaw('NULL AS restore_reason'),
                    fn (User $actor, int $id) => $this->restoreEntry($actor, $kind, $id),
                    purgePermissions: ['content.purge'],
                    purge: fn (User $actor, int $id) => $this->purgeEntry($actor, $kind, $id),
                ),
            );
        }
        $registry->register(
            new TrashSource(
                'comments',
                'site.content.comments',
                ['content.comments.manage'],
                ['content.restore'],
                fn (User $user) => ContentComment::withTrashed()
                    ->join('content_entries', 'content_entries.id', '=', 'content_comments.content_entry_id')
                    ->whereNotNull('content_comments.deleted_at')
                    ->select(['content_comments.id AS id', 'content_comments.deleted_at AS deleted_at'])
                    ->selectRaw('SUBSTR(content_comments.body, 1, 200) AS title')
                    ->selectRaw(
                        'CASE WHEN content_comments.user_id IS NULL THEN ? WHEN content_entries.deleted_at IS NOT NULL THEN ? ELSE NULL END AS restore_reason',
                        ['trash.restore_anonymized', 'trash.restore_parent_deleted'],
                    ),
                fn (User $actor, int $id) => $this->restoreComment($actor, $id),
                purgePermissions: ['content.purge'],
                purge: fn (User $actor, int $id) => $this->purgeComment($actor, $id),
            ),
        );
    }

    public function restoreEntry(User $actor, string $kind, int $id): void
    {
        DB::transaction(function () use ($actor, $kind, $id) {
            $entry = ContentEntry::onlyTrashed()->where('kind', $kind)->lockForUpdate()->findOrFail($id);
            $entry->restore();
            app(ContentHistory::class)->record($entry, $actor, 'undeleted');
            Audit::record('content.undeleted', ['id' => $entry->id], $actor);
        }, 3);
    }

    public function restoreComment(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $comments = app(ContentComments::class);
            $comment = $comments->locked($id, trashed: true);
            if (!$comment->user_id) {
                throw new HttpException(409);
            }
            $comment->recordRevision($actor, 'moderated');
            $comment->restore();
            Audit::record('content.comment_restored', ['id' => $comment->id], $actor);
        }, 3);
    }

    public function purgeEntry(User $actor, string $kind, int $id): void
    {
        DB::transaction(function () use ($actor, $kind, $id) {
            $entry = ContentEntry::onlyTrashed()->where('kind', $kind)->lockForUpdate()->findOrFail($id);
            $announcements = ContentAnnouncement::query()
                ->where('content_entry_id', $id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['status']);
            if ($announcements->contains('status', 'sending')) {
                throw new HttpException(409, __('trash.busy'));
            }
            $imageService = app(ContentImages::class);
            $images = ContentImage::query()
                ->whereIn('id', $imageService->referencedByEntry($entry))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $exclusiveImageIds = $images
                ->reject(fn (ContentImage $image) => $imageService->isReferenced($image->id, $entry->id))
                ->modelKeys();
            $attachments = ContentAttachment::withTrashed()
                ->where('content_entry_id', $id)
                ->lockForUpdate()
                ->pluck('path');
            $entry->forceDelete();
            foreach ($images as $image) {
                if (!in_array($image->id, $exclusiveImageIds, true) || $imageService->isReferenced($image->id)) {
                    continue;
                }
                $imageService->removeUnused($image->id);
            }
            foreach ($attachments as $path) {
                if (
                    str_starts_with($path, 'content-attachments/'.$id.'/') &&
                    !str_contains($path, '..') &&
                    preg_match('~^[A-Za-z0-9][A-Za-z0-9/_.-]*$~D', $path)
                ) {
                    app(FileDeletionQueue::class)->schedule($path, 'content-attachments/'.$id, [
                        'content_attachments' => 'path',
                    ]);
                }
            }
            Audit::record('content.purged', ['id' => $id, 'kind' => $kind], $actor);
        }, 3);
    }

    public function purgeComment(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $comment = app(ContentComments::class)->locked($id, trashed: true, withTrashedEntry: true);
            $comment->forceDelete();
            Audit::record('content.comment_purged', ['id' => $id], $actor);
        }, 3);
    }
}
