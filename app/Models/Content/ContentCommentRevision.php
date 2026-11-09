<?php

namespace App\Models\Content;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $body
 * @property string $state
 */
class ContentCommentRevision extends Model
{
    protected $table = 'content_comment_revisions';

    public const null UPDATED_AT = null;

    protected $guarded = ['id'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ContentComment::class, 'content_comment_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
