<?php

namespace App\Models\Content;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $content_entry_id
 * @property int|null $uploader_id
 * @property string $original_name
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContentAttachment extends Model
{
    protected $table = 'content_attachments';

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id')->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
