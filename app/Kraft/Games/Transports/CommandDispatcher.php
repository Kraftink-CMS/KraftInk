<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use App\Kraft\Link\CommandQueue;
use App\Kraft\Link\PlayerTarget;
use App\Models\Servers\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use App\Kraft\System\OperationLock;
use JsonException;

class CommandDispatcher
{
    public function __construct(private TransportRegistry $transports, private CommandQueue $queue)
    {
    }

    public function run(Server $server, int $limit = 10): int
    {
        return app(OperationLock::class)->shared(fn () => $this->dispatch($server, $limit));
    }

    private function dispatch(Server $server, int $limit): int
    {
        $transport = $this->transports->get($server);
        if (
            !$server->enabled ||
            $this->transports->requiresSetup($server) ||
            !$transport ||
            empty($transport->metadata()['commands']) ||
            app()->isDownForMaintenance() ||
            DB::transactionLevel() > 0
        ) {
            return 0;
        }
        $lock = Cache::lock('server-transport:'.$server->id, 90);
        if (!$lock->get()) {
            return 0;
        }
        try {
            if ($transport instanceof ProvidesOnlinePlayers) {
                try {
                    $players = $transport->onlinePlayers($server);
                    if (
                        count($players) > 4096 ||
                        array_filter(
                            $players,
                            fn ($id) => !is_string($id) ||
                                $id === '' ||
                                strlen($id) > 64 ||
                                preg_match('/[^A-Za-z0-9_.:+-]/', $id),
                        )
                    ) {
                        throw new TransportFailure('transports.invalid_response');
                    }
                    $players = array_values(
                        array_unique(array_map(fn (string $id) => new PlayerTarget($id)->canonical(), $players)),
                    );
                } catch (Throwable) {
                    $players = [];
                }
            } else {
                $players = [];
            }
            $commands = $this->queue->claim($server, $players, min(10, max(1, $limit)));
            foreach ($commands as $command) {
                $this->deliver($server, $transport, $command, $players);
            }

            return count($commands);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws JsonException
     */
    private function deliver(Server $server, Transport $transport, array $command, array $players): void
    {
        $id = $command['id'];
        $checksum = hash(
            'sha256',
            json_encode(
                [$server->game, $command['command'], $command['uuid'] ?? null],
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
        );
        $attempt = $server->commandAttempts()->where('command_id', $id)->first();
        if ($attempt) {
            $state = $attempt->acknowledged($checksum) ? 'executed' : 'uncertain';
            if ($state === 'uncertain') {
                $attempt->update(['state' => 'uncertain', 'updated_at' => now()]);
            }
            $this->queue->acknowledge(
                $server,
                $id,
                $command['lease'],
                $state,
                $state === 'uncertain' ? 'transports.uncertain' : null,
            );

            return;
        }
        if (
            !empty($command['requires_online']) &&
            (!($transport instanceof ProvidesOnlinePlayers) ||
                !is_string($command['uuid'] ?? null) ||
                !in_array(new PlayerTarget($command['uuid'])->canonical(), $players, true))
        ) {
            $this->queue->acknowledge($server, $id, $command['lease'], 'deferred', 'transports.online_unverified');

            return;
        }
        if (
            !is_string($command['command']) ||
            $command['command'] === '' ||
            strlen($command['command']) > 2048 ||
            !mb_check_encoding($command['command'], 'UTF-8') ||
            preg_match('/[\x00\r\n]/', $command['command'])
        ) {
            $this->queue->acknowledge($server, $id, $command['lease'], 'failed', 'transports.invalid_command');

            return;
        }
        $writing = false;
        try {
            $transport->execute($server, $command['command'], function () use (
                $server,
                $id,
                $checksum,
                &$writing,
            ): void {
                if (DB::transactionLevel() !== 0) {
                    throw new TransportFailure('transports.transaction_open', true);
                }
                $server->commandAttempts()->create([
                    'command_id' => $id,
                    'checksum' => $checksum,
                    'state' => 'writing',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $writing = true;
            });
            if (!$writing) {
                throw new TransportFailure('transports.invalid_response');
            }
            $server
                ->commandAttempts()
                ->where('command_id', $id)
                ->update(['state' => 'acknowledged', 'updated_at' => now()]);
            $state = 'executed';
            $error = null;
        } catch (Throwable $exception) {
            $state = $writing
                ? 'uncertain'
                : ($exception instanceof TransportFailure && $exception->terminal
                    ? 'failed'
                    : 'deferred');
            $error = $exception instanceof TransportFailure ? $exception->reason : 'transports.unavailable';
            if ($writing) {
                $server
                    ->commandAttempts()
                    ->where('command_id', $id)
                    ->update(['state' => 'uncertain', 'updated_at' => now()]);
            }
        }
        $this->queue->acknowledge($server, $id, $command['lease'], $state, $error);
    }
}
