<?php

namespace App\Models\Imports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $source_id
 * @property string $checksum
 * @property string|null $target_table
 * @property int|null $target_id
 */
class ImportRecord extends Model
{
    protected $table = 'import_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }

    public function scopeSourceIdentity(Builder $query, string $runId, string $table, int|string $id): void
    {
        $query->where('run_id', $runId)->where('source_table', $table)->where('source_id', (string) $id);
    }
}
