<?php

namespace App\Models\OAuth\Discord;

use App\Models\Accounts\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property string $id
 * @property int $connection_id
 * @property int|null $user_id
 * @property string $provider
 * @property string $application_id
 * @property string $subject_hash
 * @property array|null $credentials
 * @property string $reason
 * @property string $state
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoleRevocation extends Model
{
    protected $table = 'discord_role_revocations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credentials', 'subject_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'credentials' => 'encrypted:array',
            'next_attempt_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
