<?php

namespace App\Models\OAuth;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static Builder<static> identity(string $provider, string $subject)
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $subject
 */
class OAuthAccount extends Model
{
    protected $table = 'oauth_accounts';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeIdentity(Builder $query, string $provider, string $subject): void
    {
        $query->where('provider', $provider)->where('subject', $subject);
    }
}
