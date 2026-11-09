<?php

namespace App\Models\Imports;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $source
 * @property array $plan
 * @property string $status
 * @property array|null $report
 * @property string|null $error
 */
class ImportRun extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'import_runs';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['plan', 'report', 'error'];

    protected function casts(): array
    {
        return ['plan' => 'array', 'report' => 'array'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(ImportRecord::class, 'run_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ImportCredential::class, 'run_id');
    }
}
