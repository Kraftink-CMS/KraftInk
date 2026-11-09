<?php

namespace App\Models\Notifications;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\HasTextTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @method static Builder<static> unreadFor(User $user)
 * @method static Builder<static> visibleTo(User $user)
 *
 * @property int $id
 * @property int|null $author_id
 * @property int|null $audience_id
 * @property string $title
 * @property string $body
 * @property array|null $translations
 * @property string|null $url
 * @property string $audience
 * @property Carbon $published_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CommunityNotice extends Model
{
    protected $table = 'community_notices';

    use HasTextTranslations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'expires_at' => 'datetime', 'translations' => 'array'];
    }

    public function scopeUnreadFor(Builder $query, User $user): void
    {
        $query->visibleTo($user)->whereDoesntHave('reads', fn (Builder $query) => $query->where('user_id', $user->id));
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $roles = $user->effectiveRoles()->pluck('id')->all();
        $query
            ->where('published_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(
                fn (Builder $query) => $query
                    ->where('audience', 'all')
                    ->orWhere(fn (Builder $query) => $query->where('audience', 'user')->where('audience_id', $user->id))
                    ->orWhere(fn (Builder $query) => $query->where('audience', 'role')->whereIn('audience_id', $roles)),
            );
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(CommunityNoticeRead::class, 'notice_id');
    }

    public function markReadBy(User $user): CommunityNoticeRead
    {
        /** @var CommunityNoticeRead $read */
        $read = $this->reads()->firstOrCreate(['user_id' => $user->id], ['read_at' => now()]);

        return $read;
    }

    public static function markAllReadBy(User $user): int
    {
        $visible = static::visibleTo($user)
            ->select('community_notices.id')
            ->selectRaw('? as user_id, ? as read_at', [$user->id, now()->toDateTimeString()]);

        return CommunityNoticeRead::query()->insertOrIgnoreUsing(['notice_id', 'user_id', 'read_at'], $visible);
    }
}
