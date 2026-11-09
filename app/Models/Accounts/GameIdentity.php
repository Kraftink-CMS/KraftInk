<?php

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $user_id
 * @property string $game
 * @property string $uuid
 * @property string $username
 * @property array|null $metadata
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GameIdentity extends Model
{
    protected $table = 'game_identities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
