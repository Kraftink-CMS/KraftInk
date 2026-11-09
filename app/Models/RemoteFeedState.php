<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemoteFeedState extends Model
{
    protected $table = 'remote_feed_states';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['document' => 'array', 'auth_context' => 'array'];
    }
}
