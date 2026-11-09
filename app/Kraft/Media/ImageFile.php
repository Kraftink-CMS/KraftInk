<?php

namespace App\Kraft\Media;

use RuntimeException;

final readonly class ImageFile
{
    public function __construct(
        public string $path,
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
        public int $size,
    ) {
    }

    public function sha256(): string
    {
        $hash = hash_file('sha256', $this->path);
        if ($hash === false) {
            throw new RuntimeException('Cannot calculate the image checksum.');
        }

        return $hash;
    }
}
