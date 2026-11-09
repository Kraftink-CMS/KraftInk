<?php

namespace App\Kraft\System;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use App\Models\Notifications\CommunityNotice;
use App\Models\Audit\AuditWebhookDelivery;
use App\Models\Content\ContentAnnouncement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class TransientRetention
{
    public function prune(bool $dryRun = false): array
    {
        $policy = app(RetentionPolicy::class);
        $cutoff = now()->subDays($policy->days('technical'));
        $counts = [];
        $session = DB::connection(config('session.connection'));
        $sessionTable = config('session.table', 'sessions');
        if ($session->getSchemaBuilder()->hasTable($sessionTable)) {
            $counts['sessions.expired'] = $this->delete(
                $session
                    ->table($sessionTable)
                    ->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime', 120))->timestamp),
                'id',
                $dryRun,
            );
        }
        $brokers = [];
        foreach (config('auth.passwords', []) as $broker) {
            $connection = $broker['connection'] ?? config('database.default');
            $table = $broker['table'];
            $key = $connection.'.'.$table;
            $brokers[$key] = [
                'connection' => $connection,
                'table' => $table,
                'minutes' => max($brokers[$key]['minutes'] ?? 0, (int) ($broker['expire'] ?? 60)),
            ];
        }
        foreach ($brokers as $key => $broker) {
            $connection = DB::connection($broker['connection']);
            if ($connection->getSchemaBuilder()->hasTable($broker['table'])) {
                $counts['password_resets.'.$key] = $this->delete(
                    $connection
                        ->table($broker['table'])
                        ->where('created_at', '<', now()->subMinutes($broker['minutes'])),
                    'email',
                    $dryRun,
                );
            }
        }
        foreach (config('cache.stores', []) as $name => $store) {
            if (($store['driver'] ?? null) !== 'database') {
                continue;
            }
            foreach (['table' => 'connection', 'lock_table' => 'lock_connection'] as $tableKey => $connectionKey) {
                $table = $store[$tableKey] ?? ($tableKey === 'table' ? 'cache' : 'cache_locks');
                $connection = DB::connection($store[$connectionKey] ?? ($store['connection'] ?? null));
                if (!$table || !$connection->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                $query = $connection->table($table)->where('expiration', '<', now()->timestamp);
                $prefix = (string) config('cache.prefix', '');
                if ($prefix !== '') {
                    $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
                    $query->whereRaw($query->getGrammar()->wrap('key')." LIKE ? ESCAPE '!'", [$pattern]);
                }
                $counts['cache.'.$name.'.'.$tableKey] = $this->delete($query, 'key', $dryRun);
            }
        }
        $batches = DB::connection(config('queue.batching.database'));
        $batchTable = config('queue.batching.table', 'job_batches');
        if ($batches->getSchemaBuilder()->hasTable($batchTable)) {
            $counts['jobs.finished_batches'] = $this->delete(
                $batches
                    ->table($batchTable)
                    ->where('pending_jobs', 0)
                    ->where('failed_jobs', 0)
                    ->whereNotNull('finished_at')
                    ->where('finished_at', '<', $cutoff->timestamp),
                'id',
                $dryRun,
            );
        }
        $counts['notifications.expired_notices'] = $this->delete(
            CommunityNotice::query()->where('expires_at', '<', $cutoff),
            'id',
            $dryRun,
        );
        $counts['audit.webhook_deliveries'] = $this->delete(
            AuditWebhookDelivery::query()
                ->whereIn('status', ['delivered', 'cancelled'])
                ->where('updated_at', '<', now()->subDays(7)),
            'id',
            $dryRun,
        );
        $announcements = ContentAnnouncement::query()
            ->whereIn('status', ['delivered', 'cancelled'])
            ->where('updated_at', '<', $cutoff)
            ->where(
                fn ($query) => $query
                    ->whereNotNull('message_id')
                    ->orWhereNotNull('http_status')
                    ->orWhere('attempts', '>', 0),
            );
        if ($dryRun) {
            $counts['content.announcement_details'] = $announcements->count();
        } else {
            $ids = (clone $announcements)->orderBy('id')->limit(500)->pluck('id');
            $counts['content.announcement_details'] = $announcements->whereIn('id', $ids)->update([
                'message_id' => null,
                'http_status' => null,
                'attempts' => 0,
            ]);
        }

        return $counts;
    }

    private function delete(Builder|EloquentBuilder $query, string $key, bool $dryRun): int
    {
        if ($dryRun) {
            return $query->count();
        }
        $ids = (clone $query)->orderBy($key)->limit(500)->pluck($key);

        return (clone $query)->whereIn($key, $ids)->delete();
    }
}
