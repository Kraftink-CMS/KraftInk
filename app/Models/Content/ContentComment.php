<?php

namespace App\Models\Content;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $content_entry_id
 * @property int|null $user_id
 * @property string $body
 * @property string $state
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContentComment extends Model
{
    protected $table = 'content_comments';

    use SoftDeletes;

    protected $guarded = ['id'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentCommentRevision::class);
    }

    public function recordRevision(User $actor, string $action): ContentCommentRevision
    {
        return $this->revisions()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'body' => $this->body,
            'state' => $this->state,
        ]);
    }
}
