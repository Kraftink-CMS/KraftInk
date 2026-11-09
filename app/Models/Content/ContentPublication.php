<?php

namespace App\Models\Content;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property Carbon|null $lease_until
 * @property Carbon|null $dispatched_at
 */
class ContentPublication extends Model
{
    use HasUuids;

    protected $table = 'content_publications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lease_until' => 'datetime', 'dispatched_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id')->withTrashed();
    }

    public function isLeased(): bool
    {
        return $this->lease_until?->isFuture() ?? false;
    }
}
