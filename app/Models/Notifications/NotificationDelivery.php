<?php

namespace App\Models\Notifications;

use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $status
 * @property Carbon $available_at
 * @property string $channel
 * @property string $type
 * @property array $data
 * @property string|null $configuration_id
 * @property int $attempts
 * @property Carbon|null $started_at
 */
class NotificationDelivery extends Model
{
    protected $table = 'notification_deliveries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'attempts' => 'integer',
            'http_status' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(CommunityNotice::class, 'notice_id');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending')->where('available_at', '<=', now());
    }
}
