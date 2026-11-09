<?php

namespace App\Kraft\Link;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\Servers\LinkCommand;
use App\Events\AcknowledgingLinkCommand;
use App\Events\CollectingLinkCommands;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\Transports\CommandDispatcher;
use App\Models\Servers\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CommandQueue
{
    public function enqueue(
        Server $server,
        string $command,
        ?string $uuid = null,
        bool $requiresOnline = false,
        ?string $idempotencyKey = null,
    ): string {
        if (
            $idempotencyKey &&
            ($existing = LinkCommand::query()->where('idempotency_key', $idempotencyKey)->value('id'))
        ) {
            return $existing;
        }
        $id = (string) Str::uuid();
        $server->commands()->create([
            'id' => $id,
            'command' => $command,
            'player_uuid' => $uuid ? new PlayerTarget($uuid)->canonical() : null,
            'requires_online' => $requiresOnline,
            'status' => 'pending',
            'available_at' => now(),
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            DB::afterCommit(function () use ($server): void {
                $current = Server::query()->find($server->id);
                if (!$current || $current->game !== app(GameRegistry::class)->activeId()) {
                    return;
                }
                try {
                    app(CommandDispatcher::class)->run($current, 5);
                } catch (Throwable $exception) {
                    Log::warning('Server command delivery postponed', [
                        'server_id' => $server->id,
                        'exception' => $exception::class,
                    ]);
                }
            });
        }

        return $id;
    }

    public function claim(Server $server, array $players, int $limit, array $playerNames = []): array
    {
        $players = array_map(fn (string $uuid) => new PlayerTarget($uuid)->canonical(), $players);
        $playerNames = $server->connection === 'kraftlink'
            ? array_values(array_filter($playerNames, fn (string $name) => new PlayerTarget($name)->uuid() === null))
            : [];

        return DB::transaction(function () use ($server, $players, $playerNames, $limit) {
            $candidates = $server
                ->commands()
                ->claimable($players, $playerNames)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->limit($limit)
                ->get();
            $commands = collect();
            foreach ($candidates as $item) {
                /** @var LinkCommand $item */
                $target = $item->player_uuid !== null && $item->player_uuid !== '' ? new PlayerTarget($item->player_uuid) : null;
                $name = $server->connection === 'kraftlink' ? $target?->name() : null;
                $item->claim();
                $commands->push([
                    'id' => LinkCommand::DELIVERY_PREFIX.$item->id,
                    'command' => $item->command,
                    'uuid' => $name === null ? $item->player_uuid : null,
                    'player' => $name,
                    'channel' => 'core',
                    'lease' => $item->lease,
                    'lease_expires_at' => $item->lease_expires_at->toIso8601String(),
                    'requires_online' => (bool) $item->requires_online,
                ]);
            }
            $event = new CollectingLinkCommands($server, $players, $limit, $commands, $playerNames);
            event($event);

            return $event->commands->take($limit)->values()->all();
        });
    }

    public function acknowledge(Server $server, string $id, string $lease, string $state, ?string $error): void
    {
        if (!str_starts_with($id, LinkCommand::DELIVERY_PREFIX)) {
            $event = new AcknowledgingLinkCommand($server, $id, $lease, $state, $error);
            event($event);
            if (!$event->handled) {
                abort(404);
            }

            return;
        }
        DB::transaction(function () use ($server, $id, $lease, $state, $error) {
            /** @var LinkCommand|null $command */
            $command = $server->commands()->whereKey(substr($id, strlen(LinkCommand::DELIVERY_PREFIX)))->lockForUpdate()->first();
            if (!$command || !hash_equals($command->lease ?? '', $lease)) {
                abort(404);
            }
            if (!$command->acknowledge($state, $error)) {
                throw new HttpException(409);
            }
        });
    }
}
