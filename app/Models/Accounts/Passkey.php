<?php

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Model;

class Passkey extends Model
{
    protected $table = 'passkeys';

    protected $fillable = ['credential_hash', 'rp_id', 'name', 'credential', 'last_used_at'];

    protected $hidden = ['credential_hash', 'credential'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }
}
