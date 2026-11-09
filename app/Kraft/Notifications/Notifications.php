<?php

namespace App\Kraft\Notifications;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Models\Notifications\NotificationDelivery;
use App\Kraft\Html;
use App\Kraft\NavigationTargets;
use App\Kraft\System\OperationLock;
use App\Kraft\System\WebhookClient;
use App\Mail\NotificationMail;
use App\Models\Notifications\CommunityNotice;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Traits\Localizable;
use InvalidArgumentException;
use Throwable;
use Closure;
use App\Kraft\System\RetentionPolicy;
use JsonException;

class Notifications
{
    use Localizable;

    public function __construct(
        private readonly NotificationRegistry $registry,
        private readonly NotificationPreferences $preferences,
    ) {
    }

    public function notify(
        string $type,
        User $user,
        array $data,
        string $key,
        ?array $channels = null,
        ?string $resource = null,
        bool $deferred = false,
    ): ?int {
        $definition = $this->registry->get($type);
        if (!$definition) {
            return null;
        }
        $channels ??= $definition['channels'];
        if (array_diff($channels, $definition['channels'])) {
            throw new InvalidArgumentException('Unsupported notification channel.');
        }

        return DB::transaction(function () use (
            $definition,
            $user,
            $data,
            $key,
            $channels,
            $resource,
            $deferred,
        ): ?int {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$this->activeUser($user)) {
                return null;
            }
            $notice = null;
            foreach (array_unique($channels) as $channel) {
                $id = $this->enqueue($definition, $user->id, $data, $key, $channel, $resource);
                if ($channel === 'native' && !$deferred) {
                    $this->deliver($id);
                } elseif (!$deferred) {
                    $this->deliverAfterCommit($id);
                }
                $notice ??= NotificationDelivery::query()->where('id', $id)->value('notice_id');
            }

            return $notice;
        });
    }

    /**
     * @throws JsonException
     */
    public function webhook(
        string $type,
        array $data,
        string $key,
        string $configurationId,
        ?string $resource = null,
    ): void {
        $definition = $this->registry->get($type);
        if ($definition && $definition['webhook']) {
            $id = $this->enqueue($definition, null, $data, $key, 'discord', $resource, $configurationId);
            $this->deliverAfterCommit($id);
        }
    }

    private function deliverAfterCommit(string $id): void
    {
        if (config('queue.connections.'.config('queue.default').'.driver') !== 'sync') {
            return;
        }
        DB::afterCommit(function () use ($id): void {
            try {
                $this->deliver($id);
            } catch (Throwable $exception) {
                $this->log('Notification delivery postponed', $id, $exception);
            }
        });
    }

    /**
     * @throws JsonException
     */
    private function enqueue(
        array $definition,
        ?int $user,
        array $data,
        string $key,
        string $channel,
        ?string $resource,
        ?string $configuration = null,
    ): string {
        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        if (
            $key === '' ||
            strlen($key) > 240 ||
            strlen($payload) > 16384 ||
            strlen($resource ?? '') > 160 ||
            strlen($configuration ?? '') > 80
        ) {
            throw new InvalidArgumentException('Invalid notification payload.');
        }
        $id = hash('sha256', json_encode([$definition['type'], $user, $channel, $key], JSON_THROW_ON_ERROR));
        NotificationDelivery::query()->firstOrCreate(
            ['id' => $id],
            [
                'owner' => $definition['owner'],
                'type' => $definition['type'],
                'resource' => $resource,
                'user_id' => $user,
                'channel' => $channel,
                'configuration_id' => $configuration,
                'data' => $data,
                'status' => 'pending',
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $id;
    }

    public function run(int $limit = 100, ?string $owner = null): int
    {
        return app(OperationLock::class)->shared(function () use ($limit, $owner): int {
            $this->deliveries($owner)
                ->where('status', 'sending')
                ->where('started_at', '<', now()->subMinutes(10))
                ->update(['status' => 'uncertain', 'updated_at' => now()]);
            $ids = $this->deliveries($owner)
                ->pending()
                ->orderBy('created_at')
                ->limit(max(1, min(500, $limit)))
                ->pluck('id');
            $processed = 0;
            $started = microtime(true);
            foreach ($ids as $id) {
                if (microtime(true) - $started > 40) {
                    break;
                }
                $processed += (int) $this->deliver($id);
            }

            return $processed;
        });
    }

    public function deliver(string $id): bool
    {
        return app(OperationLock::class)->shared(function () use ($id): bool {
            $candidate = NotificationDelivery::query()
                ->where('id', $id)
                ->first(['user_id']);
            if (!$candidate) {
                return false;
            }
            $externalAllowed = DB::transactionLevel() === 0;
            $claimed = $this->claimDelivery($id, $candidate, $externalAllowed);
            if (!$claimed) {
                return false;
            }
            if (isset($claimed['native'])) {
                return true;
            }
            $this->sendExternal($id, $claimed);

            return true;
        });
    }

    private function claimDelivery(string $id, object $candidate, bool $externalAllowed): ?array
    {
        return DB::transaction(function () use ($id, $candidate, $externalAllowed): ?array {
            $user = $candidate->user_id ? User::query()->whereKey($candidate->user_id)->lockForUpdate()->first() : null;
            $row = NotificationDelivery::query()->where('id', $id)->lockForUpdate()->first();
            if (!$row || $row->status !== 'pending' || $row->available_at->isFuture()) {
                return null;
            }
            if ($row->available_at->lte(now()->subDays(90))) {
                $this->finish($id, ['status' => 'cancelled', 'data' => '{}']);

                return null;
            }
            if ($row->channel !== 'native' && !$externalAllowed) {
                return null;
            }
            $definition = $this->registry->get($row->type);
            if (
                !$definition ||
                ($row->channel !== 'discord' && !$this->activeUser($user)) ||
                ($row->channel !== 'discord' && !in_array($row->channel, $definition['channels'], true)) ||
                ($user && !$this->preferences->enabled($user, $definition, $row->channel))
            ) {
                $this->finish($id, ['status' => 'cancelled']);

                return null;
            }
            if ($row->channel === 'email' && !$user?->hasVerifiedEmail()) {
                $this->finish($id, ['status' => 'cancelled']);

                return null;
            }
            if (
                NotificationDelivery::query()
                    ->where('id', $id)
                    ->where('status', 'pending')
                    ->update(['status' => 'sending', 'started_at' => now(), 'updated_at' => now()]) !== 1
            ) {
                return null;
            }
            try {
                $data = $row->data;
                if (!is_array($data)) {
                    throw new InvalidArgumentException('Invalid notification payload.');
                }
                $message = $this->render($definition, $data, $user, $row->channel);
                if ($message === null) {
                    $this->finish($id, ['status' => 'cancelled']);

                    return null;
                }
            } catch (Throwable $exception) {
                $this->fail($id, $exception);

                return null;
            }
            if ($row->channel === 'native') {
                $notice = CommunityNotice::query()->create([
                    'title' => $message['title'],
                    'body' => $message['body'],
                    'url' => $message['url'],
                    'audience' => 'user',
                    'audience_id' => $user->id,
                    'published_at' => now(),
                    'expires_at' => now()->addDays(90),
                ]);
                $this->finish($id, [
                    'status' => 'delivered',
                    'notice_id' => $notice->id,
                    'delivered_at' => now(),
                    'attempts' => $row->attempts + 1,
                ]);

                return ['native' => true];
            }
            $destination = null;
            if ($row->channel === 'discord') {
                try {
                    $destination = $definition['webhook'] ? $definition['webhook']() : null;
                } catch (Throwable $exception) {
                    $this->fail($id, $exception);

                    return null;
                }
                if (
                    !$destination ||
                    ($destination['configuration_id'] ?? '') !== $row->configuration_id ||
                    !WebhookClient::validDiscordUrl($destination['url'] ?? '')
                ) {
                    $this->finish($id, ['status' => 'cancelled']);

                    return null;
                }
            }
            $this->finish($id, ['status' => 'sending', 'started_at' => now(), 'attempts' => $row->attempts + 1]);

            return compact('row', 'message', 'user', 'destination');
        }, 3);
    }

    private function sendExternal(string $id, array $claimed): void
    {
        ['row' => $row, 'message' => $message, 'user' => $user, 'destination' => $destination] = $claimed;
        try {
            $url = $message['url'] !== null ? url($message['url']) : null;
            if ($row->channel === 'email') {
                Mail::to($user->email)->locale($user->preferredLocale())->send(
                    new NotificationMail(
                        $message['title'],
                        trim(html_entity_decode(strip_tags($message['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                        $url,
                    ),
                );
                $this->finish($id, ['status' => 'delivered', 'delivered_at' => now()]);
            } elseif ($row->channel === 'discord') {
                $response = app(WebhookClient::class)->discord($destination['url'], [
                    array_filter(
                        [
                            'title' => $message['title'],
                            'description' => mb_substr(
                                html_entity_decode(strip_tags($message['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                                0,
                                4096,
                            ),
                            'url' => $url,
                        ],
                        fn ($value) => $value !== null,
                    ),
                ]);
                $result = ['status' => $response['status'], 'http_status' => $response['http_status']];
                if ($response['status'] === 'delivered') {
                    $result['delivered_at'] = now();
                } elseif ($response['retry_after'] !== null && $row->attempts < 7) {
                    $result['status'] = 'pending';
                    $result['available_at'] = now()->addSeconds($response['retry_after']);
                }
                $this->finish($id, $result);
            } else {
                $this->finish($id, ['status' => 'cancelled']);
            }
        } catch (Throwable $exception) {
            $this->finish($id, ['status' => 'uncertain']);
            $this->log('Notification delivery uncertain', $id, $exception);
        }
    }

    private function message(mixed $message): array
    {
        if (
            !is_array($message) ||
            !is_string($message['title'] ?? null) ||
            mb_strlen($message['title']) > 160 ||
            !is_string($message['body'] ?? null) ||
            strlen($message['body']) > 50000
        ) {
            throw new InvalidArgumentException('Invalid notification message.');
        }
        $url = $message['url'] ?? null;
        if ($url !== null && (!is_string($url) || strlen($url) > 500 || !$this->validUrl($url))) {
            throw new InvalidArgumentException('Invalid notification URL.');
        }

        return ['title' => $message['title'], 'body' => Html::clean($message['body']), 'url' => $url];
    }

    private function validUrl(string $url): bool
    {
        if (!NavigationTargets::validUrl($url)) {
            return false;
        }
        if (str_starts_with($url, '/') || strtolower(parse_url($url, PHP_URL_SCHEME) ?? '') === 'https') {
            return true;
        }
        $target = parse_url($url);
        $site = parse_url(url('/'));

        return ($target['scheme'] ?? '') === ($site['scheme'] ?? '') &&
            strtolower($target['host'] ?? '') === strtolower($site['host'] ?? '') &&
            ($target['port'] ?? 80) === ($site['port'] ?? 80);
    }

    private function render(array $definition, array $data, ?User $user, string $channel): ?array
    {
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            return $this->withLocale($user?->preferredLocale() ?? app()->getLocale(), function () use (
                $definition,
                $data,
                $user,
                $channel,
            ): ?array {
                if (!$definition['visible']($user, $data, $channel)) {
                    return null;
                }

                return $this->message($definition['render']($data, $user));
            });
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
    }

    private function finish(string $id, array $values): void
    {
        NotificationDelivery::query()
            ->where('id', $id)
            ->update($values + ['updated_at' => now()]);
    }

    private function fail(string $id, Throwable $exception): void
    {
        $this->finish($id, ['status' => 'failed']);
        $this->log('Notification rendering failed', $id, $exception);
    }

    private function log(string $message, string $id, Throwable $exception): void
    {
        try {
            Log::warning($message, ['delivery' => $id, 'exception' => $exception::class]);
        } catch (Throwable) {
        }
    }

    private function activeUser(?User $user): bool
    {
        return $user && !$user->anonymized_at && !$user->import_locked && !$user->isBanned();
    }

    /** @return Builder<NotificationDelivery> */
    public function deliveries(?string $owner = null): Builder
    {
        return NotificationDelivery::query()->when($owner !== null, fn ($query) => $query->where('owner', $owner));
    }

    public function retry(string $id, string $owner): void
    {
        $row = $this->deliveries($owner)->where('id', $id)->first();
        if ($row === null) {
            throw new NotFoundHttpException();
        }
        if (!in_array($row->status, ['failed', 'uncertain'], true)) {
            throw new HttpException(409);
        }
        $this->deliveries($owner)
            ->where('id', $id)
            ->whereIn('status', ['failed', 'uncertain'])
            ->update(['status' => 'pending', 'available_at' => now(), 'updated_at' => now()]);
        $this->deliverAfterCommit($id);
    }

    public function prune(bool $dryRun = false): int
    {
        return DB::transaction(function () use ($dryRun): int {
            $query = $this->deliveries()
                ->whereIn('status', ['delivered', 'cancelled'])
                ->where('updated_at', '<=', now()->subDays(app(RetentionPolicy::class)->days('technical')))
                ->where('data', '!=', '{}');
            if ($dryRun) {
                return $query->count();
            }
            $rows = $query
                ->orderBy('id')
                ->limit(500)
                ->lockForUpdate()
                ->get(['id', 'notice_id']);
            CommunityNotice::query()
                ->whereIn('id', $rows->pluck('notice_id')->filter())
                ->where('expires_at', '<=', now())
                ->delete();
            $this->deliveries()
                ->whereIn('id', $rows->pluck('id'))
                ->update(['data' => '{}', 'updated_at' => now()]);

            return $rows->count();
        }, 3);
    }

    public function forget(string $owner, ?string $resource = null, ?int $user = null): void
    {
        $this->mutate($owner, $resource, $user, function ($rows) use ($owner): void {
            CommunityNotice::query()
                ->whereIn('id', $rows->pluck('notice_id')->filter())
                ->delete();
            $this->deliveries($owner)->whereIn('id', $rows->pluck('id'))->delete();
        });
    }

    public function dismiss(string $owner, ?string $resource = null, ?int $user = null): void
    {
        $this->mutate($owner, $resource, $user, function ($rows) use ($owner): void {
            CommunityNotice::query()
                ->whereIn('id', $rows->pluck('notice_id')->filter())
                ->delete();
            $this->deliveries($owner)
                ->whereIn('id', $rows->pluck('id'))
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        });
    }

    private function mutate(string $owner, ?string $resource, ?int $user, Closure $change): void
    {
        DB::transaction(function () use ($owner, $resource, $user, $change): void {
            if ($user !== null) {
                User::query()->whereKey($user)->lockForUpdate()->first();
            }
            $rows = $this->deliveries($owner)
                ->when($resource !== null, fn ($query) => $query->where('resource', $resource))
                ->when($user !== null, fn ($query) => $query->where('user_id', $user))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'notice_id', 'status', 'started_at']);
            if (
                $rows->contains(
                    fn (NotificationDelivery $row) => $row->status === 'sending' &&
                        $row->started_at?->gte(now()->subMinutes(10)),
                )
            ) {
                throw new HttpException(409, __('notifications.busy'));
            }
            $change($rows);
        }, 3);
    }

    public function busy(string $owner, ?string $resource = null, ?int $user = null): bool
    {
        return $this->deliveries($owner)
            ->where('status', 'sending')
            ->where('started_at', '>=', now()->subMinutes(10))
            ->when($resource !== null, fn ($query) => $query->where('resource', $resource))
            ->when($user !== null, fn ($query) => $query->where('user_id', $user))
            ->exists();
    }
}
