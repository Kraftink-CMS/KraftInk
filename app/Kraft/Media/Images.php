<?php

namespace App\Kraft\Media;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Images
{
    private const array Extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    public function supportedMimeTypes(): array
    {
        return array_keys(self::Extensions);
    }

    public function canDecode(ImageFile $image): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        $contents = @file_get_contents($image->path);

        return $contents !== false && $contents !== '' && @imagecreatefromstring($contents) !== false;
    }

    public function inspect(UploadedFile|File $file): ?ImageFile
    {
        $path = $file->getPathname();
        $uploaded = $file instanceof UploadedFile;
        $dimensions = (!$uploaded || $file->isValid()) && is_file($path) ? @getimagesize($path) : false;
        if (!$dimensions || min($dimensions[0], $dimensions[1]) < 1) {
            return null;
        }
        $mime = $dimensions['mime'];
        $extension = self::Extensions[$mime] ?? null;
        $size = $uploaded ? $file->getSize() : @filesize($path);
        if ($extension === null || $size === false || ($uploaded && $mime !== $file->getMimeType())) {
            return null;
        }

        return new ImageFile($path, $mime, $extension, $dimensions[0], $dimensions[1], $size);
    }

    public function validateUpload(
        UploadedFile $file,
        ?int $maxPixels = null,
        ?int $maxBytes = null,
        string $field = 'file',
    ): ImageFile {
        $image = $this->inspect($file);
        if (
            !$image ||
            ($maxPixels !== null && $image->width * $image->height > $maxPixels) ||
            ($maxBytes !== null && $image->size > $maxBytes)
        ) {
            throw $this->invalidImage($field);
        }

        return $image;
    }

    public function prepareUpload(UploadedFile $file, ?ImageFile $inspection = null, string $field = 'file'): ImageFile
    {
        $image = $inspection ?? $this->validateUpload($file, field: $field);
        if (!$file->isValid() || $image->path !== $file->getPathname()) {
            throw $this->invalidImage($field);
        }
        $contents = ImageMetadata::strip($image);
        $result = $contents ? @getimagesizefromstring($contents) : false;
        if (
            !$result || $result['mime'] !== $image->mime ||
            !in_array([$result[0], $result[1]], [[$image->width, $image->height], [$image->height, $image->width]], true)
        ) {
            return $image;
        }
        $size = strlen($contents);
        if (file_put_contents($image->path, $contents, LOCK_EX) !== $size) {
            throw new RuntimeException('Cannot prepare the cleaned image upload.');
        }
        clearstatcache(true, $image->path);

        return new ImageFile($image->path, $image->mime, $image->extension, $result[0], $result[1], $size);
    }

    private function invalidImage(string $field): ValidationException
    {
        return ValidationException::withMessages([$field => __('site.community.invalid_image')]);
    }
}
