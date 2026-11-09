<?php

namespace App\Kraft\Link;

use App\Kraft\System\TableQuery;
use App\Models\Servers\LinkCommand;
use App\Events\CollectingLinkDeliverySources;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use App\Models\Servers\ServerCommandAttempt;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeliveryMonitor
{
    public function sources(User $user): array
    {
        $event = new CollectingLinkDeliverySources($user);
        $event->add(
            'core',
            'admin.servers.deliveries.console',
            'servers.manage',
            fn () => LinkCommand::query()
                ->join('servers', 'servers.id', '=', 'link_commands.server_id')
                ->select([
                    'link_commands.id',
                    'servers.game',
                    'link_commands.server_id',
                    'link_commands.player_uuid',
                    'link_commands.player_uuid AS player_name',
                    'link_commands.command',
                    DB::raw(
                        "CASE link_commands.status WHEN 'executed' THEN 'delivered' WHEN 'uncertain' THEN 'failed' ELSE link_commands.status END AS status",
                    ),
                    'link_commands.attempts',
                    'link_commands.requires_online',
                    'link_commands.lease_expires_at',
                    'link_commands.last_error',
                    'link_commands.created_at',
                    'link_commands.executed_at AS delivered_at',
                    'link_commands.id AS reference',
                ]),
            fn (object $row) => [
                'reference' => __('admin.servers.deliveries.manual_command'),
                'url' => null,
                'retry_url' => null,
                'cancel_url' => $row->actionable ? route('admin.servers.deliveries.cancel', $row->id) : null,
            ],
            actionable: fn (Collection $rows): array => $this->cancellableCommands($rows),
        );
        event($event);

        return $event->sources;
    }

    public function paginate(array $sources, array $filters, ?string $game, int $defaultServer): LengthAwarePaginator
    {
        $union = null;
        $cast = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'CHAR' : 'VARCHAR(255)';
        foreach ($sources as $id => $source) {
            if (!empty($filters['source']) && $filters['source'] !== $id) {
                continue;
            }
            $query = DB::query()
                ->fromSub($source['query'](), 'entries')
                ->selectRaw("CAST(id AS $cast) AS id")
                ->addSelect([
                    'game',
                    'server_id',
                    'player_uuid',
                    'player_name',
                    'command',
                    'status',
                    'attempts',
                    'requires_online',
                    'lease_expires_at',
                    'last_error',
                    'created_at',
                    'delivered_at',
                ])
                ->selectRaw("CAST(reference AS $cast) AS reference")
                ->selectRaw('? AS source', [$id]);
            if ($union === null) {
                $union = $query;
            } else {
                $union->unionAll($query);
            }
        }
        [$attention, $bindings] = DeliveryQueue::attentionCondition();
        $query = DB::query()
            ->fromSub($union, 'deliveries')
            ->select('deliveries.*')
            ->selectRaw("CASE WHEN {$attention} THEN 1 ELSE 0 END AS needs_attention", $bindings)
            ->where('game', $game);
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'attention') {
                $query->whereRaw($attention, $bindings);
            } else {
                $query->where('status', $filters['status']);
            }
        }
        if (!empty($filters['search'])) {
            TableQuery::whereContains($query, ['player_name', 'reference', 'id'], $filters['search']);
        }
        if (!empty($filters['server_id'])) {
            $server = (int) $filters['server_id'];
            $query->where(
                fn (Builder $query) => $query
                    ->where('server_id', $server)
                    ->when($server === $defaultServer, fn (Builder $query) => $query->orWhereNull('server_id')),
            );
        }

        $deliveries = TableQuery::paginate($query->orderByDesc('created_at')->orderBy('source')->orderByDesc('id'), [
            'source',
            'player_name',
            'server_id',
            'command',
            'status',
            'created_at',
        ]);
        foreach ($deliveries->getCollection()->groupBy('source') as $source => $rows) {
            $actionable = $sources[$source]['actionable'] ?? null;
            $eligible = $actionable ? array_fill_keys($actionable($rows), true) : null;
            foreach ($rows as $row) {
                $row->needs_attention = (bool) $row->needs_attention;
                $row->actionable = $eligible === null || isset($eligible[$row->id]);
            }
        }

        return $deliveries;
    }

    private function cancellableCommands(Collection $rows): array
    {
        $pending = $rows->where('status', 'pending')->whereNull('delivered_at');
        if ($pending->isEmpty()) {
            return [];
        }
        $attempts = ServerCommandAttempt::query()
            ->whereIn('server_id', $pending->pluck('server_id')->unique())
            ->whereIn('command_id', $pending->map(fn (object $row) => LinkCommand::DELIVERY_PREFIX.$row->id))
            ->get(['server_id', 'command_id'])
            ->groupBy('server_id');

        return $pending
            ->reject(
                fn (object $row) => $attempts
                    ->get($row->server_id)
                    ?->contains('command_id', LinkCommand::DELIVERY_PREFIX.$row->id),
            )
            ->pluck('id')
            ->all();
    }

    public function pendingReason(object $delivery, ?Server $server): string
    {
        if (!$server || !$server->hasFreshStatus() || (isset($delivery->game) && $delivery->game !== $server->game)) {
            return 'server';
        }
        $players = $server->status['players'] ?? null;
        $identifier = $delivery->player_uuid ?? null;
        if ($server->connection !== 'kraftlink' || !$delivery->requires_online || !$identifier || !is_array($players)) {
            return 'queued';
        }
        $target = new PlayerTarget($identifier);
        foreach ($players as $player) {
            if (!is_array($player) || !is_string($player[$target->uuid() === null ? 'name' : 'uuid'] ?? null)) {
                return 'queued';
            }
            if ($target->matches($player)) {
                return 'queued';
            }
        }

        return 'player';
    }
}
