<?php

namespace App\Kraft;

use App\Kraft\Media\Images;
use App\Kraft\Setup\Installation;
use App\Models\Media\MediaAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class Favicon
{
    public function __construct(private Settings $settings, private Installation $installation)
    {
    }

    /** @return Builder<MediaAsset> */
    public function available(): Builder
    {
        return MediaAsset::query()
            ->where('mime', 'image/png')
            ->whereColumn('width', 'height')
            ->whereBetween('width', [16, 2048])
            ->where('size', '<=', 2097152);
    }

    public function current(): ?MediaAsset
    {
        if (!$this->ready()) {
            return null;
        }
        $id = $this->settings->get('site.favicon_id');
        if (!$id) {
            return null;
        }
        $asset = $this->available()->find($id);

        return $asset && Storage::disk('local')->exists($asset->path) ? $asset : null;
    }

    public function validateSelection(?int $id): ?MediaAsset
    {
        if (!$id) {
            return null;
        }
        $asset = $this->available()->find($id);
        $image = $asset ? app(Images::class)->inspect(new File(Storage::disk('local')->path($asset->path), false)) : null;
        if (
            !$asset ||
            !$image ||
            $image->mime !== 'image/png' ||
            $image->width !== $image->height ||
            $image->width < 16 ||
            $image->width > 2048
        ) {
            throw ValidationException::withMessages(['favicon_id' => __('admin.settings.identity.favicon.invalid')]);
        }

        return $asset;
    }

    public function manifestUrl(): string
    {
        return $this->ready() ? route('site.manifest.show') : asset('images/favicons/site.webmanifest');
    }

    public function manifest(): array
    {
        $asset = $this->current();
        $icons = $asset
            ? [
                [
                    'src' => route('site.media.show', $asset),
                    'sizes' => $asset->width.'x'.$asset->height,
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
            ]
            : array_map(
                fn (int $size) => [
                    'src' => asset('images/favicons/icon-'.$size.'.png'),
                    'sizes' => $size.'x'.$size,
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [192, 512],
            );
        $name = $this->ready() ? $this->settings->get('site.name', 'KraftInk') : 'KraftInk';

        return [
            'id' => url('/'),
            'name' => $name,
            'short_name' => $name,
            'start_url' => url('/'),
            'scope' => url('/'),
            'theme_color' => '#FAF7F3',
            'background_color' => '#FAF7F3',
            'icons' => $icons,
        ];
    }

    private function ready(): bool
    {
        return $this->installation->installed() || app()->runningUnitTests();
    }
}
