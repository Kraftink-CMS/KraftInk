<?php

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read User|null $author
 */
class UserBan extends Model
{
    protected $table = 'user_bans';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected function authorName(): Attribute
    {
        return Attribute::get(fn () => $this->author?->username);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
