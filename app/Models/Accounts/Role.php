<?php

namespace App\Models\Accounts;

use App\Kraft\Auth\PermissionCatalog;
use App\Models\Concerns\HasTextTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property string|null $system_key
 * @property string $slug
 * @property string $name
 * @property string|null $color
 * @property string|null $text_color
 * @property string|null $icon
 * @property array|null $translations
 * @property int $position
 * @property string|null $permission_profile
 * @property array $permissions
 * @property array|null $permission_overrides
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends Model
{
    protected $table = 'roles';

    use HasTextTranslations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'permission_overrides' => 'array',
            'position' => 'integer',
            'translations' => 'array',
        ];
    }

    public function effectivePermissions(): array
    {
        $permissions = $this->permissions ?? [];
        if (in_array('*', $permissions, true)) {
            return array_values(array_unique($permissions));
        }
        $catalog = app(PermissionCatalog::class);
        $profile = $this->permission_profile ?? 'member';
        $defaults = in_array($profile, ['member', 'mod', 'admin'], true) ? $catalog->defaults($profile) : [];
        $permissions = array_fill_keys(array_merge($defaults, $permissions), true);
        $available = array_fill_keys($catalog->all(), true);
        foreach ($this->permission_overrides ?? [] as $permission => $enabled) {
            if (!isset($available[$permission]) || !is_bool($enabled)) {
                continue;
            }
            if ($enabled) {
                $permissions[$permission] = true;
            } else {
                unset($permissions[$permission]);
            }
        }

        return array_keys($permissions);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(RoleUser::class);
    }

    public function badgeColors(): ?array
    {
        if (!preg_match('/^#[a-fA-F0-9]{6}$/D', $this->color ?? '')) {
            return null;
        }
        if (preg_match('/^#[a-fA-F0-9]{6}$/D', $this->text_color ?? '')) {
            return ['background' => $this->color, 'text' => $this->text_color];
        }
        $channels = array_map(
            function ($offset) {
                $value = hexdec(substr($this->color, $offset, 2)) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            [1, 3, 5],
        );
        $luminance = $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;

        return ['background' => $this->color, 'text' => $luminance > 0.179 ? '#000000' : '#ffffff'];
    }
}
