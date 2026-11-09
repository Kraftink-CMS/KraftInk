<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $type
 * @property bool $enabled
 * @property string|null $retired_uuid
 */
class ExtensionInstallation extends Model
{
    protected $table = 'extension_installations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'market_provenance' => 'array'];
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('retired_uuid');
    }
}
