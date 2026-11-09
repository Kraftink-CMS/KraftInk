<?php

namespace App\Models\Content;

use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ContentEntryRole extends Pivot
{
    public $timestamps = false;

    protected $table = 'content_entry_role';

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id')->withTrashed();
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
