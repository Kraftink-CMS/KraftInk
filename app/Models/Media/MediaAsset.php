<?php

namespace App\Models\Media;

use App\Kraft\System\TableQuery;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\HasTextTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder
 *
 * @property int $id
 * @property int|null $owner_id
 * @property string $path
 * @property string $hash
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property int $width
 * @property int $height
 * @property string $alt
 * @property array|null $translations
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> search(?string $search)
 */
class MediaAsset extends Model
{
    protected $table = 'media_assets';

    use HasTextTranslations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['translations' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeSearch(Builder $query, ?string $search): void
    {
        $search = trim($search ?? '');
        if ($search === '') {
            return;
        }
        TableQuery::whereContains($query, ['original_name', 'alt'], $search);
    }
}
