<?php

namespace App\Kraft\Content;

use App\Models\Content\ContentCommentRevision;
use App\Models\Content\ContentRevision;
use App\Kraft\System\RetentionPolicy;
use App\Kraft\System\RevisionRetention;
use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentEntry;
use Illuminate\Support\Facades\DB;
use App\Kraft\Media\FileDeletionQueue;

class ContentRetention
{
    public function prune(bool $dryRun = false): array
    {
        $revisions = app(RevisionRetention::class);
        $policy = app(RetentionPolicy::class);
        $counts = [
            'content.revisions' => $revisions->prune(ContentRevision::query(), 'content_entry_id', $dryRun),
            'content.comment_revisions' => $revisions->prune(
                ContentCommentRevision::query(),
                'content_comment_id',
                $dryRun,
            ),
            'content.image_usages' => 0,
            'content.attachments' => 0,
        ];
        ContentEntry::withTrashed()
            ->orderBy('id')
            ->chunkById(100, function ($entries) use ($dryRun, &$counts, $policy) {
                /** @var ContentEntry $entry */
                foreach ($entries as $entry) {
                    DB::transaction(
                        function () use ($entry, $dryRun, &$counts, $policy) {
                            /** @var ContentEntry|null $record */
                            $record = ContentEntry::withTrashed()->lockForUpdate()->find($entry->id);
                            if (!$record) {
                                return;
                            }
                            $snapshots = $record
                                ->revisions()
                                ->latest('created_at')
                                ->latest('id')
                                ->when(
                                    $dryRun,
                                    fn ($query) => $query
                                        ->where('created_at', '>=', now()->subDays($policy->days('history')))
                                        ->limit($policy->historyLimit()),
                                )
                                ->pluck('snapshot')
                                ->all();
                            $counts['content.image_usages'] += app(ContentImages::class)->retain(
                                $record,
                                $snapshots,
                                $dryRun,
                            );
                            $attachmentIds = [];
                            foreach ($snapshots as $snapshot) {
                                $attachmentIds = array_merge($attachmentIds, $snapshot['attachment_ids'] ?? []);
                            }
                            $attachments = ContentAttachment::onlyTrashed()
                                ->where('content_entry_id', $record->id)
                                ->where('deleted_at', '<', now()->subDays($policy->days('temporary')))
                                ->whereNotIn('id', array_unique($attachmentIds))
                                ->lockForUpdate()
                                ->get();
                            $counts['content.attachments'] += $attachments->count();
                            if (!$dryRun) {
                                /** @var ContentAttachment $attachment */
                                foreach ($attachments as $attachment) {
                                    $attachment->forceDelete();
                                    $this->deleteAttachmentFile($record->id, $attachment->path);
                                }
                            }
                        },
                    );
                }
            });
        $counts['content.images'] = app(ContentImages::class)->cleanup($dryRun);

        return $counts;
    }

    private function deleteAttachmentFile(int $entryId, string $path): void
    {
        if (str_starts_with($path, 'content-attachments/'.$entryId.'/') && !str_contains($path, '..')) {
            app(FileDeletionQueue::class)->schedule($path, 'content-attachments/'.$entryId, [
                'content_attachments' => 'path',
            ]);
        }
    }
}
