<?php

namespace App\Kraft\Content;

use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentRevision;
use App\Models\Content\ContentImage;
use App\Models\Content\ContentCommentRevision;
use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;

class ContentPrivacy
{
    public function export(ExportingPersonalData $event): void
    {
        $user = $event->user;
        $event->data['content_images'] = ContentImage::query()
            ->where('uploader_id', $user->id)
            ->select(['id', 'original_name', 'mime', 'size', 'width', 'height', 'created_at'])
            ->orderBy('id')
            ->cursor();
        $event->data['content_comments'] = $user
            ->contentComments()
            ->withTrashed()
            ->select(['content_entry_id', 'body', 'state', 'created_at', 'updated_at', 'deleted_at'])
            ->orderBy('id')
            ->cursor();
        $event->data['content_reactions'] = $user
            ->contentReactions()
            ->select(['content_entry_id', 'created_at'])
            ->orderBy('id')
            ->cursor();
    }

    public function anonymize(AccountDeleting $event): void
    {
        $user = $event->user;
        app(ContentImages::class)->anonymizeUser($user->id);
        $comments = $user->contentComments()->withTrashed();
        ContentCommentRevision::query()->whereIn('content_comment_id', (clone $comments)->select('id'))->delete();
        $comments->update([
            'user_id' => null,
            'body' => '',
            'state' => 'hidden',
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
        $user->contentReactions()->delete();
        ContentCommentRevision::query()
            ->where('actor_id', $user->id)
            ->update(['actor_id' => null]);
        ContentRevision::query()
            ->where('actor_id', $user->id)
            ->update(['actor_id' => null]);
        ContentAttachment::withTrashed()
            ->where('uploader_id', $user->id)
            ->update(['uploader_id' => null]);
        $user
            ->contentEntries()
            ->withTrashed()
            ->update(['author_id' => null]);
    }
}
