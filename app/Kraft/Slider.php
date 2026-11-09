<?php

namespace App\Kraft;

use Illuminate\Support\Collection;
use App\Models\Site\Slide;
use Illuminate\Support\Facades\Storage;

class Slider
{
    public const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public const array TRANSLATION_FIELDS = [
        'title' => ['max' => 160],
        'description' => ['max' => 2000],
        'alt' => ['max' => 255],
        'link_label' => ['max' => 100],
    ];

    public function controls(Slide $slide): array
    {
        $hasLink = $slide->url && $slide->link_label && self::validUrl($slide->url);

        return [
            'hasLink' => $hasLink,
            'hasCaption' => (bool) ($slide->title || $slide->description || $hasLink),
        ];
    }

    public function published(?string $locale = null): Collection
    {
        return Slide::with('media')
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('locale')->orWhere('locale', $locale ?? app()->getLocale()))
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->whereHas('media', fn ($query) => $query->whereIn('mime', self::IMAGE_TYPES))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn ($slide) => Storage::disk('local')->exists($slide->media->path))
            ->map(function (Slide $slide) use ($locale) {
                $localized = clone $slide;
                $translations = app(TextTranslations::class);
                foreach (array_keys(self::TRANSLATION_FIELDS) as $field) {
                    $localized->$field = $translations->resolve(
                        (string) $slide->$field,
                        $slide->translations ?? [],
                        $field,
                        $slide->locale,
                        $locale,
                    );
                }

                return $localized;
            })
            ->values();
    }

    public static function validUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false &&
            $parts !== false &&
            in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true) &&
            !isset($parts['user']) &&
            !isset($parts['pass']);
    }
}
