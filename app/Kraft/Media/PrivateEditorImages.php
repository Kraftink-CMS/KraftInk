<?php

namespace App\Kraft\Media;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Kraft\System\RetentionPolicy;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateEditorImages
{
    public function __construct(protected readonly EditorImageScope $scope)
    {
    }

    public function store(User $actor, UploadedFile $file): object
    {
        if (!$actor->hasPermission($this->scope->permission) || !$this->active($actor)) {
            throw new HttpException(403);
        }
        $images = app(Images::class);
        $inspection = $images->validateUpload(
            $file,
            maxPixels: config('community.media_max_pixels', 40000000),
            maxBytes: config('community.media_max_kilobytes', 8192) * 1024,
        );
        $image = $images->prepareUpload($file, $inspection);
        $hash = $image->sha256();
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $file, $image, $hash, &$path) {
                $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if (!$this->active($current)) {
                    throw new HttpException(403);
                }
                $existing = $this->scope
                    ->images()
                    ->where('uploader_id', $actor->id)
                    ->where('hash', $hash)
                    ->lockForUpdate()
                    ->first();
                if ($existing && Storage::disk('local')->exists($existing->path)) {
                    $this->scope
                        ->images()
                        ->where('id', $existing->id)
                        ->update(['updated_at' => now()]);

                    return $existing;
                }
                $staged = $this->unreferenced()->where('uploader_id', $actor->id);
                if ((clone $staged)->count() >= 100 || (clone $staged)->sum('size') + $image->size > 134217728) {
                    throw ValidationException::withMessages(['file' => __('site.content.images.quota')]);
                }
                $id = $existing?->id ?? (string) Str::uuid();
                $directory = 'editor-images/'.$this->scope->id;
                $path = $directory.'/'.$id.'.'.$image->extension;
                DB::afterRollBack(
                    fn () => app(FileDeletionQueue::class)->schedule($path, $directory, [
                        $this->scope->images => 'path',
                    ]),
                );
                if (!Storage::disk('local')->putFileAs($directory, $image->path, basename($path))) {
                    throw new RuntimeException('Cannot store editor image.');
                }
                $data = [
                    'path' => $path,
                    'hash' => $hash,
                    'original_name' => mb_substr(
                        preg_replace('/[\\\\\/\p{C}]/u', '_', $file->getClientOriginalName()),
                        0,
                        255,
                    ),
                    'mime' => $image->mime,
                    'size' => $image->size,
                    'width' => $image->width,
                    'height' => $image->height,
                    'updated_at' => now(),
                ];
                if ($existing) {
                    $this->scope->images()->where('id', $id)->update($data);
                } else {
                    $this->scope
                        ->images()
                        ->insert($data + ['id' => $id, 'uploader_id' => $actor->id, 'created_at' => now()]);
                }

                return $this->scope->images()->find($id);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                app(FileDeletionQueue::class)->schedule($path, 'editor-images/'.$this->scope->id, [
                    $this->scope->images => 'path',
                ]);
            }
            throw $exception;
        }
    }

    public function attach(int $resourceId, string $html, User $actor): void
    {
        if (!$actor->hasPermission($this->scope->permission) || !$this->active($actor)) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($resourceId, $html, $actor) {
            $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (!$this->active($current)) {
                throw new HttpException(403);
            }
            if ($this->scope->resources()->where('id', $resourceId)->lockForUpdate()->first() === null) {
                abort(404);
            }
            $ids = $this->referenced($html);
            $images = $this->scope->images()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            foreach ($ids as $id) {
                $image = $images->get($id);
                if (
                    !$image ||
                    !Storage::disk('local')->exists($image->path) ||
                    ((int) $image->uploader_id !== $actor->id &&
                        !$this->scope->usages()->where('image_id', $id)->exists())
                ) {
                    throw ValidationException::withMessages(['body' => __('site.content.images.unavailable')]);
                }
            }
            $this->scope
                ->usages()
                ->where('resource_id', $resourceId)
                ->update(['active' => false]);
            foreach ($ids as $id) {
                $this->scope
                    ->usages()
                    ->updateOrInsert(['resource_id' => $resourceId, 'image_id' => $id], ['active' => true]);
            }
        }, 3);
    }

    public function canRead(object $image, ?User $user): bool
    {
        if ($user && !$this->active($user)) {
            return false;
        }
        if (
            $user?->hasPermission($this->scope->permission) &&
            ((int) $image->uploader_id === $user->id || $this->scope->usages()->where('image_id', $image->id)->exists())
        ) {
            return true;
        }

        return ($this->scope
            ->visible)($user)
            ->whereIn(
                'id',
                $this->scope->usages()->where('image_id', $image->id)->where('active', true)->select('resource_id'),
            )
            ->exists();
    }

    public function response(string $id, ?User $user): StreamedResponse
    {
        $image = $this->scope->images()->find($id);
        if (
            !$image ||
            !$this->canRead($image, $user) ||
            !$this->validPath($image) ||
            !Storage::disk('local')->exists($image->path)
        ) {
            abort(404);
        }

        return Storage::disk('local')->response($image->path, null, [
            'Content-Type' => $image->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex,nofollow',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function cleanup(int $limit = 500, bool $dryRun = false): int
    {
        $count = 0;
        $cutoff = now()->subDays(app(RetentionPolicy::class)->days('temporary'));
        $ids = $this->unreferenced()
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('id');
        foreach ($ids as $id) {
            $count += (int) DB::transaction(function () use ($id, $cutoff, $dryRun) {
                $image = $this->scope->images()->where('id', $id)->lockForUpdate()->first();
                if (!$image || $image->updated_at >= $cutoff->toDateTimeString() || $this->isReferenced($id)) {
                    return false;
                }
                if ($dryRun) {
                    return true;
                }
                $this->scope->images()->where('id', $id)->delete();
                if ($this->validPath($image)) {
                    app(FileDeletionQueue::class)->schedule($image->path, 'editor-images/'.$this->scope->id, [
                        $this->scope->images => 'path',
                    ]);
                }

                return true;
            }, 3);
        }

        return $count;
    }

    public function retain(int $resourceId): int
    {
        return DB::transaction(function () use ($resourceId) {
            $resource = $this->scope->resources()->where('id', $resourceId)->lockForUpdate()->first();
            if (!$resource) {
                return 0;
            }
            $current = $this->referenced($resource->body);
            $ids = $this->referencedByResource($resourceId);
            $existing = $this->scope->images()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
            foreach ($existing as $id) {
                $this->scope->usages()->insertOrIgnore([
                    'resource_id' => $resourceId,
                    'image_id' => $id,
                    'active' => false,
                ]);
            }
            $this->scope
                ->usages()
                ->where('resource_id', $resourceId)
                ->whereNotIn('image_id', $current)
                ->update(['active' => false]);
            $referenced = $this->referencedByResource($resourceId, false);

            return $this->scope
                ->usages()
                ->where('resource_id', $resourceId)
                ->whereNotIn('image_id', $referenced)
                ->delete();
        }, 3);
    }

    public function purge(int $resourceId): void
    {
        DB::transaction(function () use ($resourceId) {
            if (
                $this->scope
                    ->resources()
                    ->where('id', $resourceId)
                    ->whereNotNull('deleted_at')
                    ->lockForUpdate()
                    ->first() === null
            ) {
                abort(404);
            }
            $ids = $this->referencedByResource($resourceId);
            $images = $this->scope->images()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $this->scope->usages()->where('resource_id', $resourceId)->delete();
            foreach ($images as $image) {
                if ($this->isReferenced($image->id, $resourceId)) {
                    continue;
                }
                $this->scope->images()->where('id', $image->id)->delete();
                if ($this->validPath($image)) {
                    app(FileDeletionQueue::class)->schedule($image->path, 'editor-images/'.$this->scope->id, [
                        $this->scope->images => 'path',
                    ]);
                }
            }
        }, 3);
    }

    public function anonymizeUser(int $userId): void
    {
        $this->scope
            ->images()
            ->where('uploader_id', $userId)
            ->orderBy('id')
            ->chunkById(100, function ($images) use ($userId) {
                foreach ($images as $candidate) {
                    DB::transaction(function () use ($candidate, $userId) {
                        $image = $this->scope
                            ->images()
                            ->where('id', $candidate->id)
                            ->where('uploader_id', $userId)
                            ->lockForUpdate()
                            ->first();
                        if (!$image) {
                            return;
                        }
                        if ($this->isReferenced($image->id)) {
                            $this->scope
                                ->images()
                                ->where('id', $image->id)
                                ->update([
                                    'uploader_id' => null,
                                    'original_name' => 'image.'.pathinfo($image->path, PATHINFO_EXTENSION),
                                ]);
                        } else {
                            $this->scope->images()->where('id', $image->id)->delete();
                            if ($this->validPath($image)) {
                                app(FileDeletionQueue::class)->schedule(
                                    $image->path,
                                    'editor-images/'.$this->scope->id,
                                    [$this->scope->images => 'path'],
                                );
                            }
                        }
                    }, 3);
                }
            });
    }

    public function url(object $image): string
    {
        return route($this->scope->route, $image->id);
    }

    private function history(): ?QueryBuilder
    {
        return $this->scope->history ? DB::query()->fromSub(($this->scope->history)(), 'image_history') : null;
    }

    private function isReferenced(string $id, ?int $exceptResource = null): bool
    {
        if (
            $this->scope
                ->usages()
                ->where('image_id', $id)
                ->when($exceptResource !== null, fn ($query) => $query->where('resource_id', '!=', $exceptResource))
                ->exists()
        ) {
            return true;
        }
        if (
            $this->scope
                ->resources()
                ->where('body', 'like', '%'.$id.'%')
                ->when($exceptResource !== null, fn ($query) => $query->where('id', '!=', $exceptResource))
                ->exists()
        ) {
            return true;
        }

        return $this->history()
            ?->where('body', 'like', '%'.$id.'%')
            ->when($exceptResource !== null, fn ($query) => $query->where('resource_id', '!=', $exceptResource))
            ->exists() ?? false;
    }

    private function referencedByResource(int $resourceId, bool $includeUsages = true): array
    {
        $ids = $includeUsages
            ? $this->scope->usages()->where('resource_id', $resourceId)->pluck('image_id')->all()
            : [];
        $body = $this->scope->resources()->where('id', $resourceId)->value('body');
        $versions = [$body ?? '', ...$this->history()?->where('resource_id', $resourceId)->pluck('body')->all() ?? []];
        foreach ($versions as $version) {
            $ids = array_merge($ids, $this->referenced((string) $version));
        }

        return array_values(array_unique($ids));
    }

    private function unreferenced(): Builder
    {
        return $this->scope->images()->whereNotExists(
            fn ($query) => $query
                ->selectRaw('1')
                ->from($this->scope->usages)
                ->whereColumn($this->scope->usages.'.image_id', $this->scope->images.'.id'),
        );
    }

    private function validPath(object $image): bool
    {
        return preg_match(
            '/^editor-images\/'.preg_quote($this->scope->id, '/').'\/[a-f0-9-]{36}\.(jpg|png|webp|gif|avif)$/D',
            $image->path,
        ) === 1 && str_contains($image->path, '/'.$image->id.'.');
    }

    private function active(User $user): bool
    {
        return !$user->isBanned() && !$user->anonymized_at && !$user->import_locked;
    }

    private function referenced(string $html): array
    {
        return EditorImageReferences::ids($html, $this->scope->route);
    }
}
