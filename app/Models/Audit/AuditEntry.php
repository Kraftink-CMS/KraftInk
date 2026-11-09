<?php

namespace App\Models\Audit;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property array|null $context
 * @property string|null $ip
 * @property Carbon $created_at
 */
class AuditEntry extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    protected function asJson($value, $flags = 0): string|false
    {
        return parent::asJson($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function webhookDelivery(): HasOne
    {
        return $this->hasOne(AuditWebhookDelivery::class, 'audit_id');
    }
}
