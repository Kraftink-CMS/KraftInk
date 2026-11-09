<?php

namespace App\Models\Content;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $content_entry_id
 * @property int|null $actor_id
 * @property string $action
 * @property array $snapshot
 * @property Carbon $created_at
 */
class ContentRevision extends Model
{
    protected $table = 'content_revisions';

    public const null UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
