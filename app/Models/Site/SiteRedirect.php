<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property string $source
 * @property string $destination
 * @property int $status
 * @property bool $enabled
 * @property bool $automatic
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteRedirect extends Model
{
    protected $table = 'site_redirects';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'automatic' => 'boolean', 'status' => 'integer'];
    }
}
