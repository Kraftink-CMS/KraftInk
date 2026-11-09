<?php

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property array $scopes
 * @property string $security_stamp
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property-read User $user
 */
class ApiAccessToken extends Model
{
    protected $table = 'api_access_tokens';

    public $incrementing = false;

    public $timestamps = false;

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
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
