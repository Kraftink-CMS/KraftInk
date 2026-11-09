<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property string $id
 * @property int|null $uploader_id
 * @property string $path
 * @property string $hash
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property int $width
 * @property int $height
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $entries_exists
 */
class ContentImage extends Model
{
    use HasUuids;

    protected $table = 'content_images';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['path', 'hash'];

    protected function casts(): array
    {
        return ['uploader_id' => 'integer', 'size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(ContentEntry::class, 'content_entry_image')
            ->using(ContentEntryImage::class)
            ->withPivot('active')
            ->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
