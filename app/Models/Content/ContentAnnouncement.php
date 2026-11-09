<?php

namespace App\Models\Content;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $status
 * @property Carbon $available_at
 * @property int $attempts
 */
class ContentAnnouncement extends Model
{
    use HasUuids;

    protected $table = 'content_announcements';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'http_status' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id')->withTrashed();
    }

    public function scopeDue(Builder $query): void
    {
        $query->where('status', 'pending')->where('available_at', '<=', now());
    }
}
