<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property mixed $value
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
