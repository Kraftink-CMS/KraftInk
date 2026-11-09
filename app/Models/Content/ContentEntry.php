<?php

namespace App\Models\Content;

use App\Models\Media\MediaAsset;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Kraft\Auth\PermissionAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @method static Builder<static> published()
 * @method static Builder<static> visibleTo(?User $user)
 *
 * @property int $id
 * @property int|null $author_id
 * @property int|null $cover_media_id
 * @property string|null $cover_image_id
 * @property string $kind
 * @property string $locale
 * @property string $slug
 * @property string $title
 * @property string|null $summary
 * @property string $body
 * @property bool $is_pinned
 * @property bool $comments_enabled
 * @property bool $reactions_enabled
 * @property bool $comments_moderated
 * @property string $visibility
 * @property bool $roles_restricted
 * @property string|null $required_permission
 * @property Carbon|null $published_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaAsset|null $cover
 * @property-read ContentImage|null $privateCover
 * @property-read User|null $author
 */
class ContentEntry extends Model
{
    protected $table = 'content_entries';

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_pinned' => 'boolean',
            'comments_enabled' => 'boolean',
            'reactions_enabled' => 'boolean',
            'comments_moderated' => 'boolean',
            'roles_restricted' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now())->where('locale', app()->getLocale());
    }

    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $roles = $user?->effectiveRoles() ?? collect();
        $access = app(PermissionAccess::class);
        $permissions = $access->permissions($user, $roles);
        if (in_array('*', $permissions, true)) {
            return;
        }
        $query->where(function (Builder $visibility) use ($user, $roles) {
            $visibility->whereIn('visibility', $user ? ['public', 'auth'] : ['public']);
            if ($user) {
                $visibility->orWhere(function (Builder $restricted) use ($roles) {
                    $restricted->where('visibility', 'restricted')->where(function (Builder $grants) use ($roles) {
                        $grants
                            ->whereHas('roles', fn (Builder $role) => $role->whereIn('roles.id', $roles->pluck('id')))
                            ->orWhere(function (Builder $permissionOnly) {
                                $permissionOnly
                                    ->where('roles_restricted', false)
                                    ->doesntHave('roles')
                                    ->whereNotNull('required_permission')
                                    ->where('required_permission', '!=', '');
                            });
                    });
                });
            }
        });
        $access->filterQuery($query, 'required_permission', $permissions);
    }

    public function isVisibleTo(?User $user): bool
    {
        return self::visibleTo($user)->whereKey($this->id)->exists();
    }

    public function scopePubliclyIndexable(Builder $query): void
    {
        $query->where('visibility', 'public')
            ->where('roles_restricted', false)
            ->where(fn (Builder $query) => $query->whereNull('required_permission')->orWhere('required_permission', ''));
    }

    public function isPubliclyIndexable(): bool
    {
        return $this->visibility === 'public' && !$this->roles_restricted && !filled($this->required_permission);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_media_id');
    }

    public function privateCover(): BelongsTo
    {
        return $this->belongsTo(ContentImage::class, 'cover_image_id');
    }

    public function coverAsset(): MediaAsset|ContentImage|null
    {
        return $this->cover_image_id ? $this->privateCover : $this->cover;
    }

    public function coverUrl(): ?string
    {
        if ($this->cover_image_id) {
            return $this->privateCover ? route('site.content.cover', ['entry' => $this->id]) : null;
        }

        return $this->cover ? route('site.media.show', $this->cover) : null;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'content_entry_role')->using(ContentEntryRole::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ContentComment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ContentAttachment::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class);
    }

    public function url(): string
    {
        return url(($this->kind === 'news' ? '/news/' : '/p/').$this->slug);
    }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(ContentImage::class, 'content_entry_image')
            ->using(ContentEntryImage::class)
            ->withPivot('active');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ContentReaction::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(ContentAnnouncement::class);
    }

    public function publication(): HasOne
    {
        return $this->hasOne(ContentPublication::class);
    }
}
