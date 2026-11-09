<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ContentEntryImage extends Pivot
{
    public $timestamps = false;

    protected $table = 'content_entry_image';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id')->withTrashed();
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ContentImage::class, 'content_image_id');
    }
}
