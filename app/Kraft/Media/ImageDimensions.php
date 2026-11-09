<?php

namespace App\Kraft\Media;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ImageDimensions implements ValidationRule
{
    public function __construct(
        private int $minWidth,
        private int $minHeight,
        private int $maxWidth,
        private int $maxHeight,
        private string $message,
        private bool $square = false,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $image = $value instanceof UploadedFile ? app(Images::class)->inspect($value) : null;
        if (
            !$image ||
            $image->width < $this->minWidth ||
            $image->height < $this->minHeight ||
            $image->width > $this->maxWidth ||
            $image->height > $this->maxHeight ||
            ($this->square && $image->width !== $image->height)
        ) {
            $fail($this->message)->translate();
        }
    }
}
