<?php

namespace App\Models\Site;

use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class NavigationLinkRole extends Pivot
{
    public $timestamps = false;

    protected $table = 'navigation_link_role';

    public function navigationLink(): BelongsTo
    {
        return $this->belongsTo(NavigationLink::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
