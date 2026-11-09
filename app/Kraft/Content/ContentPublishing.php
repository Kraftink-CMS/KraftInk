<?php

namespace App\Kraft\Content;

use App\Events\ContentPublished;
use App\Models\Content\ContentEntry;
use App\Models\Content\ContentPublication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ContentPublishing
{
    public function dispatchDue(?int $entryId = null): int
    {
        $entries = ContentEntry::query()
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereDoesntHave('publication', fn ($query) => $query->whereNotNull('dispatched_at'))
            ->when($entryId, fn ($query) => $query->whereKey($entryId))
            ->orderBy('id')
            ->limit(500)
            ->pluck('id');
        $sent = 0;
        foreach ($entries as $id) {
            /** @var ContentPublication|null $publication */
            $publication = DB::transaction(function () use ($id) {
                $entry = ContentEntry::query()->lockForUpdate()->find($id);
                if (!$entry || !$entry->published_at?->isPast()) {
                    return null;
                }
                /** @var ContentPublication $publication */
                $publication = $entry->publication()->firstOrCreate();
                if ($publication->dispatched_at || $publication->isLeased()) {
                    return null;
                }
                $publication->update(['lease_until' => now()->addMinutes(5)]);

                return $publication;
            });
            if (!$publication) {
                continue;
            }
            try {
                event(new ContentPublished(ContentEntry::query()->findOrFail($id), $publication->id));
                $publication->update(['dispatched_at' => now(), 'lease_until' => null]);
                $sent++;
            } catch (Throwable $error) {
                Log::warning('Content publication event failed', [
                    'id' => $publication->id,
                    'exception' => $error::class,
                ]);
            }
        }

        return $sent;
    }
}
