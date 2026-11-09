<?php

namespace App\Models\Servers;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property string $id
 * @property int $server_id
 * @property int|null $actor_id
 * @property string $name
 * @property string $token_hash
 * @property string $security_stamp
 * @property array $scopes
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LinkAccountToken extends Model
{
    protected $table = 'link_account_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['token_hash', 'security_stamp'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(LinkAccountOperation::class, 'token_id');
    }
}
