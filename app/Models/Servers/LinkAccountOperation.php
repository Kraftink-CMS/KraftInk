<?php

namespace App\Models\Servers;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $payload_hash
 * @property array $result
 */
class LinkAccountOperation extends Model
{
    protected $table = 'link_account_operations';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['result' => 'array', 'created_at' => 'datetime'];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(LinkAccountToken::class, 'token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
