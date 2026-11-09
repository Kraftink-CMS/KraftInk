<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Collection;
use App\Models\Content\ContentEntry;
use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $parent_id
 * @property int|null $content_id
 * @property string $location
 * @property array $labels
 * @property string|null $icon
 * @property string $destination_type
 * @property string $url
 * @property string|null $route_name
 * @property string $visibility
 * @property string|null $permission
 * @property bool $roles_restricted
 * @property int $position
 * @property bool $enabled
 * @property bool $new_tab
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Role> $roles
 */
class NavigationLink extends Model
{
    protected $table = 'navigation_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['labels' => 'array', 'enabled' => 'boolean', 'new_tab' => 'boolean', 'roles_restricted' => 'boolean'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->using(NavigationLinkRole::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_id');
    }
}
