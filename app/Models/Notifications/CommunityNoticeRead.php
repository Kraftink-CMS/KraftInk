<?php

namespace App\Models\Notifications;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityNoticeRead extends Model
{
    protected $table = 'community_notice_reads';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(CommunityNotice::class, 'notice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
