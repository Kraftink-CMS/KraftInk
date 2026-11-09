<?php

namespace App\Models\Notifications;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
