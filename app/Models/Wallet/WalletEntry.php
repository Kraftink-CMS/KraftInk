<?php

namespace App\Models\Wallet;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property string $transaction_id
 * @property int $user_id
 * @property int $amount_minor
 * @property int $balance_before
 * @property int $balance_after
 * @property Carbon $created_at
 * @property-read WalletTransaction $transaction
 */
class WalletEntry extends Model
{
    protected $table = 'wallet_entries';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'transaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
