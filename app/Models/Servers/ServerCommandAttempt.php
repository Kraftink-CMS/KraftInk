<?php

namespace App\Models\Servers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $state
 * @property string $checksum
 */
class ServerCommandAttempt extends Model
{
    protected $table = 'server_command_attempts';

    protected $guarded = ['id'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function acknowledged(string $checksum): bool
    {
        return $this->state === 'acknowledged' && hash_equals($this->checksum, $checksum);
    }
}
