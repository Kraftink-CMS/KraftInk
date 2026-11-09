<?php

namespace App\Kraft\System;

use App\Models\Audit\AuditEntry;
use App\Models\Audit\AuditWebhookDelivery;
use App\Kraft\Audit;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use RuntimeException;
use JsonException;

class AuditWebhook
{
    /**
     * @throws JsonException
     */
    public function enqueue(AuditEntry $audit): void
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        if (!$settings->get('audit.webhook.enabled', false)) {
            return;
        }
        $configuration = $settings->get('audit.webhook.configuration_id');
        if (!$configuration) {
            return;
        }
        $id = (string) Str::uuid();
        $payload = json_encode(
            [
                'id' => $id,
                'type' => $audit->action,
                'occurred_at' => now()->toAtomString(),
                'actor_id' => $audit->user_id,
                'context' => Audit::sanitize($audit->context ?? []),
            ],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $audit->webhookDelivery()->create([
            'id' => $id,
            'configuration_id' => $configuration,
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->deliverAfterCommit($id);
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

    public function deliver(?string $id = null): bool
    {
        if (DB::transactionLevel() > 0) {
            return false;
        }

        return app(OperationLock::class)->shared(fn () => $this->deliverLocked($id));
    }

    private function deliverLocked(?string $id): bool
    {
        $delivery = DB::transaction(function () use ($id) {
            AuditWebhookDelivery::query()
                ->where('status', 'processing')
                ->where('lease_expires_at', '<', now())
                ->where('attempts', '>=', 8)
                ->update([
                    'status' => 'failed',
                    'error' => 'attempt_limit',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
            $entry = AuditWebhookDelivery::query()
                ->when($id, fn ($query) => $query->where('id', $id))
                ->where('attempts', '<', 8)
                ->where(function ($query) {
                    $query
                        ->where(fn ($pending) => $pending->due())
                        ->orWhere(
                            fn ($expired) => $expired
                                ->where('status', 'processing')
                                ->where('lease_expires_at', '<', now()),
                        );
                })
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();
            if (!$entry) {
                return null;
            }
            $entry->lease_token = (string) Str::uuid();
            $entry->attempts++;
            AuditWebhookDelivery::query()
                ->where('id', $entry->id)
                ->update([
                    'status' => 'processing',
                    'attempts' => $entry->attempts,
                    'lease_token' => $entry->lease_token,
                    'lease_expires_at' => now()->addSeconds(90),
                    'updated_at' => now(),
                ]);

            return $entry;
        }, 3);
        if (!$delivery) {
            return false;
        }
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $changes = ['lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()];
        if (
            !$settings->get('audit.webhook.enabled', false) ||
            $delivery->configuration_id !== $settings->get('audit.webhook.configuration_id')
        ) {
            $changes += ['status' => 'cancelled', 'error' => 'configuration_changed'];
        } else {
            $status = null;
            try {
                /** @var Secrets $secrets */
                $secrets = app(Secrets::class);
                $url = $secrets->get('audit.webhook.url');
                $secret = $secrets->get('audit.webhook.secret');
                if (strlen($secret) < 32) {
                    throw new RuntimeException('Webhook signing secret unavailable.');
                }
                $payload = $delivery->payload;
                $timestamp = (string) now()->timestamp;
                $response = app(WebhookClient::class)->postJson($url, $payload, [
                    'X-Kraftink-Event' => $delivery->id,
                    'X-Kraftink-Timestamp' => $timestamp,
                    'X-Kraftink-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
                    'User-Agent' => 'KraftInk-CMS',
                ]);
                $status = $response->status();
                $error = $response->successful() ? null : 'http_failure';
            } catch (Throwable) {
                $error = 'delivery_failed';
            }
            if ($error === null) {
                $changes += [
                    'status' => 'delivered',
                    'http_status' => $status,
                    'error' => null,
                    'delivered_at' => now(),
                ];
            } else {
                $delays = [60, 300, 900, 3600, 10800, 21600, 43200, 86400];
                $changes += [
                    'status' => $delivery->attempts >= 8 ? 'failed' : 'pending',
                    'http_status' => $status,
                    'error' => $error,
                    'available_at' => now()->addSeconds($delays[min($delivery->attempts - 1, 7)]),
                ];
            }
        }
        AuditWebhookDelivery::query()
            ->where('id', $delivery->id)
            ->where('lease_token', $delivery->lease_token)
            ->update($changes);

        return true;
    }
}
