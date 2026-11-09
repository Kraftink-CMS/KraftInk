<?php

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RoleUser extends Pivot
{
    protected $table = 'role_user';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'role_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
