<?php

namespace App\Models\Imports;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array $payload
 */
class ImportCredential extends Model
{
    protected $table = 'import_credentials';

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }
}
