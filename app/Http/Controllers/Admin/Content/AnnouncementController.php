<?php

namespace App\Http\Controllers\Admin\Content;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Models\Content\ContentAnnouncement;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Content\Discord\Announcements;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use App\Kraft\System\WebhookClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(Settings $settings, Secrets $secrets): View
    {
        return view('admin.content.announcements.index', [
            'enabled' => $settings->get('content.discord.enabled', false),
            'hasWebhook' => $secrets->get('content.discord.webhook') !== '',
            'deliveries' => TableQuery::paginate(
                ContentAnnouncement::query()
                    ->leftJoin('content_entries', 'content_entries.id', '=', 'content_announcements.content_entry_id')
                    ->select(['content_announcements.*', 'content_entries.title'])
                    ->latest('content_announcements.created_at'),
                [
                    'title' => 'content_entries.title',
                    'status' => 'content_announcements.status',
                    'attempts' => 'content_announcements.attempts',
                    'created_at' => 'content_announcements.created_at',
                ],
                search: ['content_entries.title'],
            ),
        ]);
    }

    public function store(
        Request $request,
        Settings $settings,
        Secrets $secrets,
        SensitiveAction $security,
    ): RedirectResponse {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'webhook_url' => 'nullable|string|max:500',
            'clear_webhook' => 'sometimes|boolean',
        ]);
        $previousUrl = $secrets->get('content.discord.webhook');
        $url = $request->boolean('clear_webhook') ? '' : $data['webhook_url'] ?? $previousUrl;
        if (($url !== '' && !WebhookClient::validDiscordUrl($url)) || ($request->boolean('enabled') && $url === '')) {
            throw ValidationException::withMessages([
                'webhook_url' => __('admin.integrations.announcements.invalid_url'),
            ]);
        }
        if ($url !== '' && $url !== $previousUrl) {
            $security->confirm($request);
        }
        DB::transaction(function () use ($request, $settings, $secrets, $url) {
            $changed = $url !== $secrets->get('content.discord.webhook');
            if ($changed || !$request->boolean('enabled')) {
                ContentAnnouncement::query()
                    ->whereIn('status', ['pending', 'sending', 'failed', 'uncertain'])
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            if ($changed || !$settings->get('content.discord.configuration_id')) {
                $settings->set('content.discord.configuration_id', (string) Str::uuid());
            }
            $secrets->set('content.discord.webhook', $url);
            $settings->set('content.discord.enabled', $request->boolean('enabled'));
            Audit::record('content.announcements_configured', ['enabled' => $request->boolean('enabled')]);
        });

        return to_route('admin.content.announcements.index')->with(
            'status',
            __(
                $request->boolean('clear_webhook')
                    ? 'admin.integrations.announcements.webhook_deleted'
                    : 'admin.integrations.announcements.saved',
            ),
        );
    }

    public function retry(
        Request $request,
        Settings $settings,
        string $delivery,
        Announcements $announcements,
    ): RedirectResponse {
        $request->validate(['confirmed' => 'accepted']);

        if (!$settings->get('content.discord.enabled', false)) {
            throw new HttpException(422, __('admin.integrations.announcements.disabled'));
        }
        $changed = ContentAnnouncement::query()
            ->where('id', $delivery)
            ->whereIn('status', ['failed', 'uncertain', 'cancelled'])
            ->update([
                'status' => 'pending',
                'configuration_id' => $settings->get('content.discord.configuration_id'),
                'attempts' => 0,
                'available_at' => now(),
                'started_at' => null,
                'http_status' => null,
                'updated_at' => now(),
            ]);
        if (!$changed) {
            abort(404);
        }
        Audit::record('content.announcement_retried', ['id' => $delivery]);
        $announcements->deliverAfterCommit($delivery);

        return to_route('admin.content.announcements.index')->with(
            'status',
            __('admin.integrations.announcements.retry_queued'),
        );
    }
}
