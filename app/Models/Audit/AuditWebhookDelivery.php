<?php

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $lease_token
 * @property int $attempts
 */
class AuditWebhookDelivery extends Model
{
    use HasUuids;

    protected $table = 'audit_webhook_deliveries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['payload', 'lease_token'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted',
            'attempts' => 'integer',
            'http_status' => 'integer',
            'available_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(AuditEntry::class, 'audit_id');
    }

    public function scopeDue(Builder $query): void
    {
        $query->where('status', 'pending')->where('available_at', '<=', now());
    }
}
