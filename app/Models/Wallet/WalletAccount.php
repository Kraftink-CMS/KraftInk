<?php

namespace App\Models\Wallet;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $user_id
 * @property int $balance_minor
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WalletAccount extends Model
{
    protected $table = 'wallet_accounts';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['balance_minor' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class, 'user_id', 'user_id');
    }
}
