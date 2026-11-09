<?php

namespace App\Kraft\Media;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ImageUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        if (
            $parts &&
            !isset($parts['user']) &&
            !isset($parts['pass']) &&
            strtolower($parts['scheme'] ?? '') === 'https' &&
            filter_var($value, FILTER_VALIDATE_URL)
        ) {
            return;
        }
        if ($parts && preg_match('~/media/([1-9][0-9]*)$~D', $parts['path'] ?? '', $matches)) {
            $asset = app(MediaSelection::class)->selected($matches[1], 'library');
            if (
                $asset &&
                in_array($value, [route('site.media.show', $asset), route('site.media.show', $asset, false)], true)
            ) {
                return;
            }
        }
        $fail('validation.url')->translate();
    }
}
