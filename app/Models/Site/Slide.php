<?php

namespace App\Models\Site;

use App\Models\Media\MediaAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $media_asset_id
 * @property string|null $title
 * @property string|null $description
 * @property string $alt
 * @property string|null $url
 * @property string|null $link_label
 * @property array|null $translations
 * @property string|null $locale
 * @property int $position
 * @property bool $enabled
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Slide extends Model
{
    protected $table = 'slides';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'position' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'translations' => 'array',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function publicationState(): string
    {
        if (!$this->enabled || !$this->media_asset_id) {
            return 'disabled';
        }
        if ($this->starts_at?->isFuture()) {
            return 'scheduled';
        }

        return $this->ends_at && $this->ends_at->lte(now()) ? 'expired' : 'visible';
    }
}
