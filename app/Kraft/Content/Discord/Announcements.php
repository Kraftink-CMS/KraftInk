<?php

namespace App\Kraft\Content\Discord;

use App\Models\Content\ContentAnnouncement;
use App\Events\ContentPublished;
use App\Kraft\LocalePolicy;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\WebhookClient;
use App\Models\Content\ContentEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use App\Kraft\System\OperationLock;

class Announcements
{
    public function __construct(private Settings $settings, private Secrets $secrets)
    {
    }

    public function published(ContentPublished $event): void
    {
        if (!$this->settings->get('content.discord.enabled', false) || !$this->eligible($event->entry)) {
            return;
        }
        $configuration = $this->settings->get('content.discord.configuration_id');
        if (
            !is_string($configuration) ||
            !Str::isUuid($configuration) ||
            !WebhookClient::validDiscordUrl($this->secrets->get('content.discord.webhook'))
        ) {
            return;
        }
        ContentAnnouncement::query()->insertOrIgnore([
            'id' => $event->id,
            'content_entry_id' => $event->entry->id,
            'configuration_id' => $configuration,
            'status' => 'pending',
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->deliverAfterCommit($event->id);
    }

    public function deliverAfterCommit(string $id): void
    {
        if (config('queue.connections.'.config('queue.default').'.driver') !== 'sync') {
            return;
        }
        DB::afterCommit(function () use ($id): void {
            try {
                $this->deliver($id);
            } catch (Throwable) {
            }
        });
    }

    public function run(int $limit = 50): int
    {
        return app(OperationLock::class)->shared(fn () => $this->runLocked($limit));
    }

    private function runLocked(int $limit): int
    {
        ContentAnnouncement::query()
            ->where('status', 'sending')
            ->where('started_at', '<', now()->subMinutes(10))
            ->update(['status' => 'uncertain', 'updated_at' => now()]);
        $ids = ContentAnnouncement::query()
            ->due()
            ->orderBy('available_at')
            ->limit(max(1, min(100, $limit)))
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
    }

    public function deliver(string $id): bool
    {
        if (DB::transactionLevel() > 0) {
            return false;
        }

        return app(OperationLock::class)->shared(fn () => $this->deliverLocked($id));
    }

    private function deliverLocked(string $id): bool
    {
        $delivery = DB::transaction(function () use ($id) {
            $row = ContentAnnouncement::query()->where('id', $id)->lockForUpdate()->first();
            if (!$row || $row->status !== 'pending' || now()->lessThan($row->available_at)) {
                return null;
            }
            ContentAnnouncement::query()
                ->where('id', $id)
                ->update([
                    'status' => 'sending',
                    'started_at' => now(),
                    'attempts' => $row->attempts + 1,
                    'updated_at' => now(),
                ]);
            $row->attempts++;

            return $row;
        });
        if (!$delivery) {
            return false;
        }
        $entry = ContentEntry::query()->find($delivery->content_entry_id);
        $url = $this->secrets->get('content.discord.webhook');
        if (
            !$this->settings->get('content.discord.enabled', false) ||
            $delivery->configuration_id !== $this->settings->get('content.discord.configuration_id') ||
            !$entry ||
            !$this->eligible($entry) ||
            !WebhookClient::validDiscordUrl($url)
        ) {
            $this->finish($id, ['status' => 'cancelled']);

            return true;
        }
        try {
            $response = app(WebhookClient::class)->discord($url, [
                [
                    'title' => mb_substr($entry->title, 0, 256),
                    'description' => mb_substr(
                        html_entity_decode(strip_tags($entry->summary ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        0,
                        2000,
                    ),
                    'url' => $entry->url(),
                    'timestamp' => $entry->published_at->toIso8601String(),
                ],
            ]);
            $values = ['status' => $response['status'], 'http_status' => $response['http_status']];
            if ($response['status'] === 'delivered') {
                $values += [
                    'delivered_at' => now(),
                    'message_id' => $response['message_id'],
                ];
            } elseif ($response['retry_after'] !== null && $delivery->attempts < 8) {
                $values['status'] = 'pending';
                $values['available_at'] = now()->addSeconds($response['retry_after']);
            }
            $this->finish($id, $values);
        } catch (Throwable) {
            $this->finish($id, ['status' => 'uncertain']);
        }

        return true;
    }

    private function eligible(ContentEntry $entry): bool
    {
        $language = app(LocalePolicy::class);

        return $entry->kind === 'news' &&
            $entry->published_at?->lessThanOrEqualTo(now()) &&
            !$entry->trashed() &&
            $entry->isVisibleTo(null) &&
            ($language->allowsChoice() || $entry->locale === $language->defaultLocale());
    }

    private function finish(string $id, array $values): void
    {
        ContentAnnouncement::query()
            ->where('id', $id)
            ->where('status', 'sending')
            ->update($values + ['updated_at' => now()]);
    }
}
