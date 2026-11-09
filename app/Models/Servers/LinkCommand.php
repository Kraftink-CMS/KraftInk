<?php

namespace App\Models\Servers;

use App\Kraft\Link\DeliveryQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Random\RandomException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $attempts
 * @property string $status
 * @property string $id
 * @property string $command
 * @property string|null $player_uuid
 * @property string|null $lease
 * @property Carbon|null $lease_expires_at
 * @property bool $requires_online
 */
class LinkCommand extends Model
{
    public const string DELIVERY_PREFIX = 'core:';

    protected $table = 'link_commands';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['lease'];

    protected function casts(): array
    {
        return [
            'requires_online' => 'boolean',
            'attempts' => 'integer',
            'lease_expires_at' => 'datetime',
            'available_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @throws RandomException
     */
    public function claim(): void
    {
        $this->update([
            'status' => 'processing',
            'lease' => bin2hex(random_bytes(24)),
            'lease_expires_at' => now()->addMinutes(5),
            'attempts' => $this->attempts + 1,
        ]);
    }

    public function acknowledge(string $state, ?string $error): bool
    {
        if (in_array($this->status, ['executed', 'uncertain', 'failed'], true)) {
            return true;
        }
        if (
            $this->status !== 'processing' ||
            !in_array($state, ['executed', 'uncertain', 'failed', 'deferred'], true)
        ) {
            return false;
        }
        $values = [
            'status' => $state === 'deferred' ? 'pending' : $state,
            'last_error' => $error ? mb_substr($error, 0, 1000) : null,
        ];
        if ($state === 'executed') {
            $values['executed_at'] = now();
        }
        if ($state === 'deferred') {
            $values['available_at'] = now()->addSeconds(30);
            $values['attempts'] = max(0, $this->attempts - 1);
        }
        $this->update($values);

        return true;
    }

    public function scopeClaimable(Builder $query, array $players, array $playerNames = []): void
    {
        $query
            ->where('attempts', '<', DeliveryQueue::MAX_ATTEMPTS)
            ->where(
                fn (Builder $query) => $query
                    ->where('status', 'pending')
                    ->orWhere(
                        fn (Builder $query) => $query
                            ->where('status', 'processing')
                            ->where('lease_expires_at', '<', now()),
                    ),
            )
            ->where(fn (Builder $query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(
                fn (Builder $query) => $query
                    ->where('requires_online', false)
                    ->orWhereIn('player_uuid', $players)
                    ->when(
                        $playerNames !== [],
                        fn (Builder $query) => $query
                            ->orWhereIn('player_uuid', $playerNames)
                            ->orWhereIn(DB::raw('LOWER(player_uuid)'), array_map('mb_strtolower', $playerNames)),
                    ),
            );
    }
}
