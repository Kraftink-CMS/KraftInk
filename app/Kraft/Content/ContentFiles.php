<?php

namespace App\Kraft\Content;

use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentEntry;
use App\Models\Accounts\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Media\Images;

class ContentFiles
{
    public function store(ContentEntry $entry, UploadedFile $file, User $actor): ContentAttachment
    {
        $mime = $file->getMimeType();
        $image = str_starts_with((string) $mime, 'image/') ? app(Images::class)->prepareUpload($file) : null;
        $mime = $image?->mime ?? $mime;
        $size = $image?->size ?? $file->getSize();
        $name = Str::limit(preg_replace('/[\\\\\/\p{C}]/u', '_', $file->getClientOriginalName()), 220, '');
        $extension = $image?->extension ?? $file->extension();
        $path = $file->storeAs('content-attachments/'.$entry->id, Str::uuid().'.'.$extension, 'local');
        if (!$path) {
            throw new RuntimeException('Cannot store content attachment.');
        }
        try {
            return DB::transaction(function () use ($entry, $actor, $name, $path, $mime, $size) {
                DB::afterRollBack(
                    fn () => app(FileDeletionQueue::class)->schedule(
                        $path,
                        'content-attachments/'.$entry->id,
                        ['content_attachments' => 'path'],
                    ),
                );
                $entry = ContentEntry::query()->lockForUpdate()->findOrFail($entry->id);
                app(ContentHistory::class)->record($entry, $actor, 'before_attachment');
                $entry->touch();

                return $entry->attachments()->create([
                    'uploader_id' => $actor->id,
                    'original_name' => $name,
                    'path' => $path,
                    'mime' => $mime,
                    'size' => $size,
                ]);
            });
        } catch (Throwable $error) {
            app(FileDeletionQueue::class)->schedule($path, 'content-attachments/'.$entry->id, [
                'content_attachments' => 'path',
            ]);
            throw $error;
        }
    }
}
