<?php

namespace App\Models\Media;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static Builder<static> available()
 *
 * @property string $path
 * @property string $prefix
 * @property array $references
 * @property int $attempts
 * @property Carbon $available_at
 */
class FileDeletion extends Model
{
    protected $table = 'file_deletions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['references' => 'array', 'attempts' => 'integer', 'available_at' => 'datetime'];
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->where('available_at', '<=', now());
    }

    public function postpone(): void
    {
        $attempts = $this->attempts + 1;
        $this->update([
            'attempts' => $attempts,
            'available_at' => now()->addSeconds(min(86400, 60 * 2 ** min($attempts - 1, 11))),
        ]);
    }
}
