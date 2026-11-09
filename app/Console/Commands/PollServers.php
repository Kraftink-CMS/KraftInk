<?php

namespace App\Console\Commands;

use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\Transports\CommandDispatcher;
use App\Kraft\Games\Transports\TransportRegistry;
use App\Models\Servers\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Throwable;
use App\Kraft\System\OperationLock;

class PollServers extends Command
{
    protected $signature = 'servers:poll {--limit=20}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('admin.servers.transport.process_description'));
    }

    public function handle(TransportRegistry $transports, CommandDispatcher $dispatcher, GameRegistry $games): int
    {
        return app(OperationLock::class)->shared(fn () => $this->poll($transports, $dispatcher, $games));
    }

    private function poll(TransportRegistry $transports, CommandDispatcher $dispatcher, GameRegistry $games): int
    {
        if (app()->isDownForMaintenance() || DB::transactionLevel() > 0) {
            return self::FAILURE;
        }
        $started = microtime(true);
        $game = $games->activeId();
        $cursorKey = 'servers.poll.cursor.'.($game ?? 'none');
        $cursor = (int) Cache::get($cursorKey, 0);
        $query = Server::query()
            ->where('enabled', true)
            ->where('game', $game)
            ->whereIn('connection', array_keys($transports->metadata($game ?? '')))
            ->orderBy('id');
        $limit = min(100, max(1, (int) $this->option('limit')));
        $servers = (clone $query)->where('id', '>', $cursor)->limit($limit)->get();
        if ($servers->isEmpty() && $cursor) {
            $servers = $query->limit($limit)->get();
        }
        /** @var Server $server */
        foreach ($servers as $server) {
            if (microtime(true) - $started > 40) {
                break;
            }
            $transport = $transports->get($server);
            Cache::put($cursorKey, $server->id, now()->addDays(7));
            if (!$transport || $transports->requiresSetup($server)) {
                continue;
            }
            if (!empty($transport->metadata()['status'])) {
                try {
                    $server->forceFill(['status' => $transport->status($server), 'last_seen_at' => now()])->save();
                } catch (Throwable) {
                }
            }
            $dispatcher->run($server, 5);
        }

        return self::SUCCESS;
    }
}
