<?php

namespace App\Kraft\Link;

use App\Events\AcknowledgingLinkCommand;
use App\Events\CollectingLinkCommands;
use App\Kraft\Settings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DeliveryQueue
{
    public const int MAX_ATTEMPTS = 10;

    public function __construct(private Settings $settings)
    {
    }

    public static function attentionCondition(): array
    {
        return [
            '(status = ? OR (status = ? AND lease_expires_at <= ?) OR (status = ? AND attempts >= ?))',
            ['failed', 'processing', now(), 'pending', self::MAX_ATTEMPTS],
        ];
    }

    public static function retryValues(): array
    {
        return [
            'status' => 'pending',
            'attempts' => 0,
            'lease_token' => null,
            'lease_expires_at' => null,
            'link_server_id' => null,
            'available_at' => now(),
        ];
    }

    public function collect(CollectingLinkCommands $event, string $channel, Builder $deliveries): void
    {
        $prefix = $this->prefix($channel);
        $limit = $event->limit - $event->commands->count();
        if ($limit <= 0) {
            return;
        }
        $commands = DB::transaction(function () use ($event, $channel, $prefix, $deliveries, $limit) {
            $server = $event->server;
            $default = (int) $this->settings->get('link.default_server', 0);
            $records = (clone $deliveries)
                ->where('game', $server->game)
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->where(
                    fn (Builder $query) => $query
                        ->where('server_id', $server->id)
                        ->when($default === $server->id, fn (Builder $query) => $query->orWhereNull('server_id')),
                )
                ->where(fn (Builder $query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->where(
                    fn (Builder $query) => $query
                        ->where('requires_online', false)
                        ->orWhereIn('player_uuid', $event->players)
                        ->when($event->playerNames !== [], fn (Builder $query) => $query->orWhereIn(
                            DB::raw('LOWER(player_uuid)'),
                            array_map('mb_strtolower', $event->playerNames),
                        )),
                )
                ->where(
                    fn (Builder $query) => $query
                        ->where('status', 'pending')
                        ->orWhere(
                            fn (Builder $query) => $query
                                ->where('status', 'processing')
                                ->where('lease_expires_at', '<', now()),
                        ),
                )
                ->orderBy('created_at')
                ->lockForUpdate()
                ->limit($limit)
                ->get();
            $commands = [];
            foreach ($records as $delivery) {
                $target = filled($delivery->player_uuid) ? new PlayerTarget($delivery->player_uuid) : null;
                $name = $server->connection === 'kraftlink' ? $target?->name() : null;
                $lease = Str::random(64);
                $expires = now()->addMinutes(5);
                $delivery
                    ->newQuery()
                    ->whereKey($delivery->getKey())
                    ->update([
                        'status' => 'processing',
                        'attempts' => $delivery->attempts + 1,
                        'lease_token' => hash('sha256', $lease),
                        'lease_expires_at' => $expires,
                        'link_server_id' => $server->id,
                    ]);
                $commands[] = [
                    'id' => $prefix.$delivery->getKey(),
                    'command' => $delivery->command,
                    'player' => $name ?? $delivery->player_name,
                    'uuid' => $name === null ? $delivery->player_uuid : null,
                    'channel' => $channel,
                    'lease' => $lease,
                    'lease_expires_at' => $expires->toIso8601String(),
                    'requires_online' => (bool) $delivery->requires_online,
                ];
            }

            return $commands;
        }, 3);
        $event->commands->push(...$commands);
    }

    public function acknowledge(
        AcknowledgingLinkCommand $event,
        string $channel,
        Builder $deliveries,
        ?Closure $onCancelled = null,
    ): void {
        $prefix = $this->prefix($channel);
        if (!str_starts_with($event->id, $prefix)) {
            return;
        }
        if (!in_array($event->state, ['executed', 'failed', 'uncertain', 'deferred'], true)) {
            throw new HttpException(422);
        }
        DB::transaction(function () use ($event, $deliveries, $prefix, $onCancelled): void {
            $delivery = (clone $deliveries)->whereKey(substr($event->id, strlen($prefix)))->lockForUpdate()->first();
            if (
                !$delivery ||
                (int) $delivery->link_server_id !== $event->server->id ||
                $delivery->game !== $event->server->game ||
                !hash_equals($delivery->lease_token ?? '', hash('sha256', $event->lease))
            ) {
                throw new HttpException(409);
            }
            if ($delivery->status === 'cancelled' && $onCancelled !== null) {
                $onCancelled($delivery, $event->state);

                return;
            }
            if (
                in_array($delivery->status, ['delivered', 'failed'], true) ||
                ($delivery->status === 'pending' &&
                    $event->state === 'deferred' &&
                    $delivery->available_at &&
                    now()->lt($delivery->available_at))
            ) {
                return;
            }
            if (
                $delivery->status !== 'processing' ||
                !$delivery->lease_expires_at ||
                !now()->lt($delivery->lease_expires_at)
            ) {
                throw new HttpException(409);
            }
            $executed = $event->state === 'executed';
            $values =
                $event->state === 'deferred'
                    ? [
                        'status' => 'pending',
                        'available_at' => now()->addSeconds(30),
                        'attempts' => max(0, $delivery->attempts - 1),
                        'last_error' => null,
                    ]
                    : [
                        'status' => $executed ? 'delivered' : 'failed',
                        'delivered_at' => $executed ? now() : null,
                        'last_error' => $executed ? null : mb_substr($event->error ?? $event->state, 0, 2000),
                    ];
            $delivery->newQuery()->whereKey($delivery->getKey())->update($values);
        }, 3);
        $event->handled = true;
    }

    private function prefix(string $channel): string
    {
        if ($channel === 'core' || !preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $channel)) {
            throw new InvalidArgumentException('Invalid link delivery channel.');
        }

        return $channel.':';
    }
}
