<?php

namespace App\Models\Wallet;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * @mixin Builder<static>
 *
 * @property string $id
 * @property int|null $actor_id
 * @property string $operation_key
 * @property string $payload_hash
 * @property string $kind
 * @property int $amount_minor
 * @property string $currency
 * @property string $reason
 * @property array|null $context
 * @property Carbon $created_at
 */
class WalletTransaction extends Model
{
    protected $table = 'wallet_transactions';

    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'context' => 'array', 'created_at' => 'datetime'];
    }

    public function reasonLabel(): string
    {
        $reason = (string) $this->reason;
        $key = str_starts_with($reason, 'wallet.')
            ? 'site.account.wallet.'.substr($reason, strlen('wallet.'))
            : $reason;

        $label = Lang::has($key) ? __($key) : __($reason);

        return is_string($label) ? $label : $reason;
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class, 'transaction_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
