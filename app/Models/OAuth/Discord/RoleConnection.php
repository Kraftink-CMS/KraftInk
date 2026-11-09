<?php

namespace App\Models\OAuth\Discord;

use App\Models\Accounts\User;
use App\Models\OAuth\OAuthAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $oauth_account_id
 * @property string $provider
 * @property string $application_id
 * @property string $subject
 * @property string $access_token
 * @property string|null $refresh_token
 * @property array $client_credentials
 * @property array $fields
 * @property string|null $metadata_hash
 * @property string|null $last_error
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $consented_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable $next_sync_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoleConnection extends Model
{
    protected $table = 'discord_role_connections';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token', 'client_credentials', 'subject'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'client_credentials' => 'encrypted:array',
            'fields' => 'array',
            'expires_at' => 'immutable_datetime',
            'consented_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'next_sync_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function oauthAccount(): BelongsTo
    {
        return $this->belongsTo(OAuthAccount::class);
    }
}
