<?php

namespace App\Kraft\Media;

use App\Kraft\Audit;
use App\Models\Media\MediaAsset;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use LogicException;
use Throwable;

class MediaLibrary
{
    public function store(UploadedFile $file, ?int $ownerId, string $alt = '', ?Closure $trackWrite = null): MediaAsset
    {
        return $this->withUploadLock($file, fn (Closure $write) => $write($ownerId, $alt, $trackWrite));
    }

    public function withUploadLock(UploadedFile $file, Closure $operation): mixed
    {
        $images = app(Images::class);
        $inspection = $images->validateUpload($file, maxPixels: config('community.media_max_pixels'));
        $image = $images->prepareUpload($file, $inspection);
        $hash = $image->sha256();
        $path = 'media/'.$hash.'.'.$image->extension;
        $directory = storage_path('framework/cache/media-upload-locks');
        File::ensureDirectoryExists($directory);
        $handle = fopen($directory.'/'.$hash.'.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Cannot lock uploaded image.');
        }
        try {
            return $operation(function (?int $ownerId, string $alt = '', ?Closure $trackWrite = null) use (
                $file,
                $image,
                $hash,
                $path,
                $handle,
            ): MediaAsset {
                if (!is_resource($handle)) {
                    throw new LogicException('The uploaded image lock has been released.');
                }

                return DB::transaction(function () use (
                    $file,
                    $ownerId,
                    $alt,
                    $trackWrite,
                    $image,
                    $hash,
                    $path,
                ) {
                    $existing = MediaAsset::query()->where('hash', $hash)->lockForUpdate()->first();
                    $path = $existing?->path ?? $path;
                    $created = !$existing && !Storage::disk('local')->exists($path);
                    try {
                        if (!Storage::disk('local')->exists($path)) {
                            if ($created) {
                                $trackWrite?->__invoke($path);
                            }
                            if (!Storage::disk('local')->putFileAs(dirname($path), $image->path, basename($path))) {
                                throw new RuntimeException('Cannot store uploaded image.');
                            }
                        }
                        $asset =
                            $existing ??
                            MediaAsset::query()->firstOrCreate(
                                ['hash' => $hash],
                                [
                                    'owner_id' => $ownerId,
                                    'path' => $path,
                                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                                    'mime' => $image->mime,
                                    'size' => $image->size,
                                    'width' => $image->width,
                                    'height' => $image->height,
                                    'alt' => $alt,
                                ],
                            );
                        Audit::record('media.uploaded', ['id' => $asset->id]);

                        return $asset;
                    } catch (Throwable $exception) {
                        if ($created) {
                            Storage::disk('local')->delete($path);
                        }
                        throw $exception;
                    }
                });
            });
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
