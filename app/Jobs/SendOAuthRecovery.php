<?php

namespace App\Jobs;

use App\Kraft\Audit;
use App\Kraft\OAuth\OAuthChanges;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\DatabaseJobRecord;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Throwable;
use RuntimeException;

class SendOAuthRecovery implements ShouldQueue, ShouldBeUnique, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 86400;

    public int $timeout = 30;

    public function __construct(public int $userId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function backoff(): array
    {
        return [60, 180, 600, 1800];
    }

    public static function request(int $userId): void
    {
        self::requestMany([$userId]);
    }

    public static function requestMany(array $userIds): void
    {
        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return;
        }
        /** @var DatabaseQueue $queue */
        $queue = Queue::connection('database');
        if ($queue->getDatabase() !== DB::connection()) {
            DB::afterCommit(function () use ($userIds): void {
                foreach ($userIds as $userId) {
                    self::dispatch($userId)->onConnection('database');
                }
            });

            return;
        }
        DB::transaction(function () use ($userIds): void {
            $ids = [];
            $lock = app(UniqueLock::class);
            foreach ($userIds as $userId) {
                $job = new self($userId)->onConnection('database')->beforeCommit();
                if (!$lock->acquire($job)) {
                    continue;
                }
                DB::afterRollBack(fn () => $lock->release($job));
                $id = Bus::dispatch($job);
                if ($id !== null) {
                    $ids[] = $id;
                } else {
                    $lock->release($job);
                }
            }
            if ($ids && config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
                DB::afterCommit(fn () => self::sendImmediate($ids));
            }
        });
    }

    private static function sendImmediate(array $ids): void
    {
        if (app()->isDownForMaintenance()) {
            return;
        }
        $started = microtime(true);
        foreach (array_slice($ids, 0, 3) as $id) {
            if (microtime(true) - $started >= 5) {
                break;
            }
            try {
                app(OperationLock::class)->shared(function () use ($id): void {
                    /** @var DatabaseQueue $queue */
                    $queue = Queue::connection('database');
                    $database = $queue->getDatabase();
                    $table = config('queue.connections.database.table', 'jobs');
                    $row = $database->transaction(function () use ($database, $table, $id): ?object {
                        $row = $database
                            ->table($table)
                            ->where('id', $id)
                            ->whereNull('reserved_at')
                            ->where('available_at', '<=', now()->timestamp)
                            ->lockForUpdate()
                            ->first();
                        if (!$row) {
                            return null;
                        }
                        $row->reserved_at = now()->timestamp;
                        $row->attempts++;
                        $database
                            ->table($table)
                            ->where('id', $id)
                            ->update([
                                'reserved_at' => $row->reserved_at,
                                'attempts' => $row->attempts,
                            ]);

                        return $row;
                    });
                    if (!$row) {
                        return;
                    }
                    $job = new DatabaseJob(app(), $queue, new DatabaseJobRecord($row), 'database', $row->queue);
                    try {
                        $job->fire();
                    } catch (Throwable $exception) {
                        if (!$job->isDeletedOrReleased()) {
                            $job->release(60);
                        }
                        throw $exception;
                    }
                });
            } catch (Throwable $exception) {
                try {
                    Log::warning('OAuth recovery delivery postponed', ['job' => $id, 'exception' => $exception::class]);
                } catch (Throwable) {
                }
            }
        }
    }

    public function handle(OAuthChanges $changes): void
    {
        if (!$this->send($changes)) {
            $this->release(60);
        }
    }

    private function send(OAuthChanges $changes): bool
    {
        $user = User::query()->find($this->userId);
        if (
            !$user ||
            !$user->oauth_recovery_required_at ||
            $user->anonymized_at ||
            $user->import_locked ||
            $user->isBanned()
        ) {
            return true;
        }
        if ($changes->usable($user)) {
            $user->forceFill(['oauth_recovery_required_at' => null])->save();

            return true;
        }
        if (
            !$user->hasVerifiedEmail() ||
            !filter_var($user->email, FILTER_VALIDATE_EMAIL) ||
            str_ends_with($user->email, '.invalid')
        ) {
            throw new RuntimeException('OAuth recovery requires a verified email address.');
        }
        $status = Password::sendResetLink(['email' => $user->email]);
        if ($status === Password::RESET_THROTTLED) {
            return false;
        }
        if ($status !== Password::RESET_LINK_SENT) {
            throw new RuntimeException('OAuth recovery notification could not be queued.');
        }
        Audit::record('oauth.recovery_sent', ['id' => $user->id], $user);

        return true;
    }

    public function failed(?Throwable $error): void
    {
        Audit::record('oauth.recovery_failed', ['id' => $this->userId, 'error' => $error ? get_class($error) : null]);
    }
}
