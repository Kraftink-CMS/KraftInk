<?php

namespace App\Kraft\Media;

use Imagick;
use ImagickException;

final class ImageMetadata
{
    public static function strip(ImageFile $file): ?string
    {
        return ExifTool::strip($file) ?? self::stripWithImagick($file);
    }

    private static function stripWithImagick(ImageFile $file): ?string
    {
        if (!extension_loaded('imagick')) {
            return null;
        }

        $format = match ($file->mime) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
        $maxPixels = config('community.media_max_pixels', 40000000);
        if (
            $format === null || $file->width * $file->height > $maxPixels ||
            $file->size > config('community.media_max_kilobytes', 8192) * 1024
        ) {
            return null;
        }

        $contents = @file_get_contents($file->path);
        if (!$contents || self::isAnimated($file->mime, $contents)) {
            return null;
        }

        $image = new Imagick();
        try {
            if ($file->mime === 'image/gif') {
                if (
                    !$image->pingImageBlob($contents) || $image->getNumberImages() !== 1 ||
                    $image->getImageWidth() * $image->getImageHeight() > $maxPixels
                ) {
                    return null;
                }
                $image->clear();
            }
            if (!$image->setFormat($format) || !$image->readImageBlob($contents) || $image->getNumberImages() !== 1) {
                return null;
            }
            if ($image->getImageOrientation() > Imagick::ORIENTATION_TOPLEFT) {
                if (!method_exists($image, 'autoOrient')) {
                    return null;
                }
                $image->autoOrient();
            }

            return $image->stripImage() ? $image->getImageBlob() : null;
        } catch (ImagickException) {
            return null;
        } finally {
            $image->clear();
        }
    }

    private static function isAnimated(string $mime, string $contents): bool
    {
        return match ($mime) {
            'image/png' => str_contains($contents, "\0\0\0\x08acTL"),
            'image/webp' => substr($contents, 12, 4) === 'VP8X' && (ord($contents[20] ?? "\0") & 2) !== 0,
            default => false,
        };
    }
}
