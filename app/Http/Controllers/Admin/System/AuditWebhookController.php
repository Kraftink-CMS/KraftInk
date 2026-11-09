<?php

namespace App\Http\Controllers\Admin\System;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Kraft\System\TableQuery;
use App\Models\Audit\AuditWebhookDelivery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\AuditWebhook;
use App\Kraft\System\SensitiveAction;
use App\Kraft\System\WebhookTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AuditWebhookController extends Controller
{
    public function index(Settings $settings, Secrets $secrets): View
    {
        return view('admin.operations.webhooks.index', [
            'enabled' => $settings->get('audit.webhook.enabled', false),
            'host' => parse_url($secrets->get('audit.webhook.url'), PHP_URL_HOST),
            'hasSecret' => $secrets->get('audit.webhook.secret') !== '',
            'deliveries' => TableQuery::paginate(
                AuditWebhookDelivery::query()
                    ->select([
                        'id',
                        'audit_id',
                        'status',
                        'attempts',
                        'http_status',
                        'error',
                        'available_at',
                        'created_at',
                        'delivered_at',
                    ])
                    ->latest('created_at'),
                ['created_at', 'status', 'attempts', 'http_status'],
            ),
        ]);
    }

    public function store(
        Request $request,
        Settings $settings,
        Secrets $secrets,
        WebhookTarget $target,
        SensitiveAction $security,
    ): RedirectResponse {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'webhook_url' => 'nullable|string|max:2000',
            'webhook_secret' => 'nullable|string|min:32|max:512',
        ]);
        $previousUrl = $secrets->get('audit.webhook.url');
        $previousSecret = $secrets->get('audit.webhook.secret');
        $url = $data['webhook_url'] ?? $previousUrl;
        $secret = $data['webhook_secret'] ?? $previousSecret;
        if ($request->boolean('enabled') && (!$url || strlen($secret) < 32)) {
            throw ValidationException::withMessages(['enabled' => __('admin.operations.webhook_required')]);
        }
        if ($url !== '') {
            try {
                $target->options($url);
            } catch (Throwable) {
                throw ValidationException::withMessages(['webhook_url' => __('admin.operations.webhook_invalid_url')]);
            }
        }
        if ($url !== $previousUrl || $secret !== $previousSecret) {
            $security->confirm($request);
        }
        DB::transaction(function () use ($settings, $secrets, $url, $secret, $request) {
            $changed = $url !== $secrets->get('audit.webhook.url') || $secret !== $secrets->get('audit.webhook.secret');
            if ($changed || !$request->boolean('enabled')) {
                AuditWebhookDelivery::query()
                    ->whereIn('status', ['pending', 'processing'])
                    ->update([
                        'status' => 'cancelled',
                        'error' => 'configuration_changed',
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);
            }
            if ($changed || !$settings->get('audit.webhook.configuration_id')) {
                $settings->set('audit.webhook.configuration_id', (string) Str::uuid());
            }
            $secrets->set('audit.webhook.url', $url);
            $secrets->set('audit.webhook.secret', $secret);
            $settings->set('audit.webhook.enabled', $request->boolean('enabled'));
            Audit::record('audit.webhook_configured', ['enabled' => $request->boolean('enabled')]);
        });

        return back()->with('status', __('admin.operations.saved'));
    }

    public function test(Settings $settings, AuditWebhook $webhook): RedirectResponse
    {
        if (!$settings->get('audit.webhook.enabled', false)) {
            throw new HttpException(422, __('admin.operations.webhook_disabled'));
        }
        $audit = Audit::record('audit.webhook_test');
        $entry = AuditWebhookDelivery::query()->where('audit_id', $audit)->first();
        if ($entry) {
            $webhook->deliver($entry->id);
        }
        $status = $entry ? AuditWebhookDelivery::query()->where('id', $entry->id)->value('status') : null;

        return back()->with(
            'status',
            $status === 'delivered'
                ? __('admin.operations.webhook_test_sent')
                : __('admin.operations.webhook_test_failed'),
        );
    }

    public function retry(string $delivery, Settings $settings, AuditWebhook $webhook): RedirectResponse
    {
        if (!$settings->get('audit.webhook.enabled', false)) {
            throw new HttpException(422, __('admin.operations.webhook_disabled'));
        }
        $count = AuditWebhookDelivery::query()
            ->where('id', $delivery)
            ->where('configuration_id', $settings->get('audit.webhook.configuration_id'))
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'error' => null,
                'http_status' => null,
                'available_at' => now(),
                'updated_at' => now(),
            ]);
        if ($count !== 1) {
            throw new NotFoundHttpException();
        }
        $webhook->deliverAfterCommit($delivery);

        return back()->with('status', __('admin.operations.webhook_retry_queued'));
    }
}
