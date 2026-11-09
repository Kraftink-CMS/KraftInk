<?php

namespace App\Kraft\Content;

use App\Models\Content\ContentEntryImage;
use App\Models\Content\ContentEntry;
use App\Models\Content\ContentImage;
use App\Models\Content\ContentRevision;
use App\Models\Accounts\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Kraft\Media\EditorImageReferences;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Media\ImageFile;
use App\Kraft\Media\Images;
use App\Kraft\System\RetentionPolicy;

class ContentImages
{
    public function validateUpload(UploadedFile $file, string $field = 'file'): ImageFile
    {
        return app(Images::class)->validateUpload(
            $file,
            maxPixels: config('community.media_max_pixels', 40000000),
            maxBytes: config('community.media_max_kilobytes', 8192) * 1024,
            field: $field,
        );
    }

    public function store(User $actor, UploadedFile $file, ?Closure $trackWrite = null): ContentImage
    {
        $upload = app(Images::class)->prepareUpload($file, $this->validateUpload($file));
        $hash = $upload->sha256();
        $createdPath = null;
        try {
            return DB::transaction(function () use (
                $actor,
                $file,
                $upload,
                $hash,
                $trackWrite,
                &$createdPath,
            ) {
                User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $existing = ContentImage::query()
                    ->where('uploader_id', $actor->id)
                    ->where('hash', $hash)
                    ->lockForUpdate()
                    ->first();
                if ($existing && Storage::disk('local')->exists($existing->path)) {
                    $existing->touch();

                    return $existing;
                }
                $staged = ContentImage::query()->where('uploader_id', $actor->id)->doesntHave('entries');
                if ((clone $staged)->count() >= 100 || (clone $staged)->sum('size') + $upload->size > 134217728) {
                    throw ValidationException::withMessages(['file' => __('site.content.images_quota')]);
                }
                $id = $existing?->id ?? (string) Str::uuid();
                $path = 'content-images/'.$id.'.'.$upload->extension;
                if (!$existing && !Storage::disk('local')->exists($path)) {
                    $createdPath = $path;
                    $trackWrite?->__invoke($path);
                    DB::afterRollBack(
                        fn () => app(FileDeletionQueue::class)->schedule($path, 'content-images', [
                            'content_images' => 'path',
                        ]),
                    );
                }
                if (!Storage::disk('local')->putFileAs('content-images', $upload->path, basename($path))) {
                    throw new RuntimeException('Cannot store editor image.');
                }
                $image = $existing ?? new ContentImage(['id' => $id, 'uploader_id' => $actor->id]);
                $image
                    ->fill([
                        'path' => $path,
                        'hash' => $hash,
                        'original_name' => mb_substr(
                            preg_replace('/[\\\\\/\p{C}]/u', '_', $file->getClientOriginalName()),
                            0,
                            255,
                        ),
                        'mime' => $upload->mime,
                        'size' => $upload->size,
                        'width' => $upload->width,
                        'height' => $upload->height,
                    ])
                    ->save();

                return $image;
            });
        } catch (Throwable $exception) {
            if ($createdPath !== null) {
                app(FileDeletionQueue::class)->schedule($createdPath, 'content-images', [
                    'content_images' => 'path',
                ]);
            }
            throw $exception;
        }
    }

    public function attach(ContentEntry $entry, User $actor): void
    {
        $ids = $this->referenced($entry->body);
        if ($entry->cover_image_id) {
            $ids = array_values(array_unique([...$ids, $entry->cover_image_id]));
        }
        $images = ContentImage::query()
            ->whereIn('id', $ids)
            ->withExists('entries')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        foreach ($ids as $id) {
            /** @var ContentImage|null $image */
            $image = $images->get($id);
            $allowed =
                $image &&
                ($image->uploader_id === $actor->id ||
                    ($id === $entry->cover_image_id
                        ? $image->entries()->whereKey($entry->id)->exists()
                        : $image->entries_exists));
            if (!$allowed || !Storage::disk('local')->exists($image->path)) {
                $field = $id === $entry->cover_image_id ? 'cover_image_id' : 'body';
                throw ValidationException::withMessages([$field => __('site.content.image_unavailable')]);
            }
        }
        ContentEntryImage::query()
            ->where('content_entry_id', $entry->id)
            ->update(['active' => false]);
        foreach ($ids as $id) {
            ContentEntryImage::query()->updateOrInsert(
                ['content_entry_id' => $entry->id, 'content_image_id' => $id],
                ['active' => true],
            );
        }
    }

    public function canRead(ContentImage $image, ?User $user): bool
    {
        if (
            $user?->hasPermission('content.manage') &&
            ($image->uploader_id === $user->id || $image->entries()->exists())
        ) {
            return true;
        }

        return $image
            ->entries()
            ->whereNull('content_entries.deleted_at')
            ->wherePivot('active', true)
            ->published()
            ->visibleTo($user)
            ->exists();
    }

    public function cleanup(bool $dryRun = false): int
    {
        $count = 0;
        $cutoff = now()->subDays(app(RetentionPolicy::class)->days('temporary'));
        $ids = ContentImage::query()
            ->doesntHave('entries')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(1000)
            ->pluck('id');
        foreach ($ids as $id) {
            $deleted = DB::transaction(function () use ($id, $cutoff, $dryRun): bool {
                $image = ContentImage::query()->whereKey($id)->lockForUpdate()->first();
                if (!$image || $this->isReferenced($id) || $image->updated_at->greaterThanOrEqualTo($cutoff)) {
                    return false;
                }
                if ($dryRun) {
                    return true;
                }

                return $this->removeUnused($id);
            });
            $count += (int) $deleted;
        }

        return $count;
    }

    public function removeUnused(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $image = ContentImage::query()->whereKey($id)->lockForUpdate()->first();
            if (!$image || $this->isReferenced($id)) {
                return false;
            }
            $path = $image->path;
            $image->delete();
            if (
                str_starts_with($path, 'content-images/') &&
                !str_contains($path, '..') &&
                preg_match('~^[A-Za-z0-9][A-Za-z0-9/_.-]*$~D', $path)
            ) {
                app(FileDeletionQueue::class)->schedule($path, 'content-images', [
                    'content_images' => 'path',
                ]);
            }

            return true;
        }, 3);
    }

    public function anonymizeUser(int $userId): void
    {
        ContentImage::query()
            ->where('uploader_id', $userId)
            ->orderBy('id')
            ->chunkById(100, function ($images) use ($userId) {
                foreach ($images as $image) {
                    DB::transaction(function () use ($image, $userId) {
                        $current = ContentImage::query()
                            ->whereKey($image->id)
                            ->where('uploader_id', $userId)
                            ->lockForUpdate()
                            ->first();
                        if (!$current) {
                            return;
                        }
                        if ($this->isReferenced($current->id)) {
                            $current->update(['uploader_id' => null, 'original_name' => 'image']);
                        } else {
                            $this->removeUnused($current->id);
                        }
                    }, 3);
                }
            });
    }

    public function referencedByEntry(ContentEntry $entry): array
    {
        $ids = ContentEntryImage::query()->where('content_entry_id', $entry->id)->pluck('content_image_id')->all();
        $ids = array_merge($ids, $this->referenced($entry->body), [$entry->cover_image_id]);
        foreach ($entry->revisions()->cursor() as $revision) {
            $snapshot = $revision->snapshot;
            $ids = array_merge($ids, $this->referenced($snapshot['body'] ?? ''), [$snapshot['cover_image_id'] ?? null]);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function isReferenced(string $id, ?int $excludingEntry = null): bool
    {
        if (
            ContentEntryImage::query()
                ->where('content_image_id', $id)
                ->when($excludingEntry !== null, fn ($query) => $query->where('content_entry_id', '!=', $excludingEntry))
                ->exists()
        ) {
            return true;
        }
        $entries = ContentEntry::withTrashed()
            ->when($excludingEntry !== null, fn ($query) => $query->whereKeyNot($excludingEntry))
            ->where(fn ($query) => $query->where('cover_image_id', $id)->orWhere('body', 'like', '%'.$id.'%'))
            ->select(['cover_image_id', 'body'])
            ->cursor();
        /** @var ContentEntry $entry */
        foreach ($entries as $entry) {
            if ($entry->cover_image_id === $id || in_array($id, $this->referenced($entry->body), true)) {
                return true;
            }
        }
        $revisions = ContentRevision::query()
            ->when($excludingEntry !== null, fn ($query) => $query->where('content_entry_id', '!=', $excludingEntry))
            ->where(
                fn ($query) => $query
                    ->where('snapshot->cover_image_id', $id)
                    ->orWhere('snapshot->body', 'like', '%'.$id.'%'),
            )
            ->select('snapshot')
            ->cursor();
        /** @var ContentRevision $revision */
        foreach ($revisions as $revision) {
            $snapshot = $revision->snapshot;
            if (
                ($snapshot['cover_image_id'] ?? null) === $id ||
                in_array($id, $this->referenced($snapshot['body'] ?? ''), true)
            ) {
                return true;
            }
        }

        return false;
    }

    public function retain(ContentEntry $entry, array $snapshots, bool $dryRun = false): int
    {
        $ids = $this->referenced($entry->body);
        $ids[] = $entry->cover_image_id;
        foreach ($snapshots as $snapshot) {
            $ids = array_merge($ids, $this->referenced($snapshot['body'] ?? ''));
            $ids[] = $snapshot['cover_image_id'] ?? null;
        }
        $query = ContentEntryImage::query()
            ->where('content_entry_id', $entry->id)
            ->where('active', false)
            ->whereNotIn('content_image_id', array_values(array_unique(array_filter($ids))));

        if ($dryRun) {
            return $query->count();
        }
        $removed = $query->delete();
        foreach (
            ContentImage::query()->whereIn('id', array_values(array_unique(array_filter($ids))))->pluck('id') as $id
        ) {
            ContentEntryImage::query()->insertOrIgnore([
                'content_entry_id' => $entry->id,
                'content_image_id' => $id,
                'active' => false,
            ]);
        }

        return $removed;
    }

    private function referenced(string $html): array
    {
        return EditorImageReferences::ids($html, 'site.content.images.show');
    }
}
