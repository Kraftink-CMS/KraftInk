<?php

namespace App\Models\Content;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentReaction extends Model
{
    protected $table = 'content_reactions';

    public const null UPDATED_AT = null;

    protected $guarded = ['id'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
