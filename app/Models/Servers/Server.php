<?php

namespace App\Models\Servers;

use App\Kraft\Settings;
use App\Models\Concerns\HasTextTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property string $name
 * @property array|null $translations
 * @property string $game
 * @property string $host
 * @property int $port
 * @property bool $show_port
 * @property bool $show_players
 * @property string $connection
 * @property string|null $secret
 * @property string|null $token_hash
 * @property array|null $transport_options
 * @property bool $enabled
 * @property array|null $status
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Server extends Model
{
    protected $table = 'servers';

    use HasTextTranslations;

    protected $guarded = ['id'];

    protected $hidden = ['secret', 'token_hash'];

    protected $attributes = [
        'enabled' => true,
        'show_port' => true,
        'show_players' => true,
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'enabled' => 'boolean',
            'show_port' => 'boolean',
            'show_players' => 'boolean',
            'status' => 'array',
            'transport_options' => 'array',
            'last_seen_at' => 'datetime',
            'translations' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $server): void {
            if (
                $server->isDirty(['game', 'host', 'port', 'connection', 'secret', 'token_hash']) ||
                ($server->isDirty('transport_options') &&
                    (array) $server->getOriginal('transport_options') !== (array) $server->transport_options)
            ) {
                $server->forceFill(['status' => null, 'last_seen_at' => null]);
            }
        });
        static::deleted(function (self $server): void {
            $settings = app(Settings::class);
            if ((int) $settings->get('servers.primary') === $server->id) {
                $settings->set('servers.primary', null);
            }
        });
    }

    public function scopeLinkedKraftLink(Builder $query, ?string $game = null): void
    {
        $query->where('connection', 'kraftlink')->where('enabled', true)->whereNotNull('token_hash')
            ->when($game !== null, fn (Builder $query) => $query->where('game', $game));
    }

    public function publicAddress(): string
    {
        return $this->show_port
            ? (str_contains($this->host, ':') ? '['.$this->host.']' : $this->host).':'.$this->port
            : $this->host;
    }

    public function hasFreshStatus(): bool
    {
        return $this->enabled && $this->last_seen_at && $this->last_seen_at->gte(now()->subSeconds(90));
    }

    public function onlinePlayerCount(): ?int
    {
        if (!$this->hasFreshStatus()) {
            return null;
        }
        if (isset($this->status['players_online'])) {
            return max(0, (int) $this->status['players_online']);
        }

        return is_array($this->status['players'] ?? null) ? count($this->status['players']) : null;
    }

    public function playerCapacity(): ?int
    {
        return $this->hasFreshStatus() && isset($this->status['max_players'])
            ? max(0, (int) $this->status['max_players'])
            : null;
    }

    public function commands(): HasMany
    {
        return $this->hasMany(LinkCommand::class);
    }

    public function commandAttempts(): HasMany
    {
        return $this->hasMany(ServerCommandAttempt::class);
    }

    public function accountTokens(): HasMany
    {
        return $this->hasMany(LinkAccountToken::class);
    }
}
