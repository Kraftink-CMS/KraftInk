<?php

namespace App\Http\Controllers\Admin\System;

use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Settings;
use App\Kraft\SiteDates;
use App\Kraft\System\AuditRetention;
use App\Kraft\System\SensitiveAction;
use App\Models\Audit\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request, Settings $settings): View
    {
        return view('admin.operations.audit.index', [
            'entries' => TableQuery::paginate($this->query($request)->with('actor:id,username')->latest('id'), [
                'created_at',
                'action',
                'ip',
            ]),
            'retentionDays' => $settings->get('audit.retention_days', 365),
        ]);
    }

    public function show(AuditEntry $entry): View
    {
        return view('admin.operations.audit.show', [
            'entry' => $entry->load('actor:id,username'),
            'context' => Audit::sanitize($entry->context ?? []),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($request)->with('actor:id,username');
        Audit::record('audit.exported');

        return response()->streamDownload(
            function () use ($query) {
                echo "[\n";
                $first = true;
                $query->chunkById(500, function ($entries) use (&$first) {
                    foreach ($entries as $entry) {
                        echo ($first ? '' : ",\n").
                            json_encode(
                                [
                                    'id' => $entry->id,
                                    'action' => $entry->action,
                                    'actor' => $entry->actor?->username,
                                    'user_id' => $entry->user_id,
                                    'ip' => $entry->ip,
                                    'created_at' => $entry->created_at?->toAtomString(),
                                    'context' => Audit::sanitize($entry->context ?? []),
                                ],
                                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
                            );
                        $first = false;
                    }
                });
                echo "\n]";
            },
            'kraftink-audit-'.now()->format('Y-m-d').'.json',
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function retention(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate(['retention_days' => 'required|integer|between:7,3650']);
        $settings->set('audit.retention_days', (int) $data['retention_days']);
        Audit::record('audit.retention_changed', ['days' => $data['retention_days']]);

        return back()->with('status', __('admin.operations.saved'));
    }

    public function purge(Request $request, AuditRetention $retention): RedirectResponse
    {
        $request->validate(['confirmed' => 'accepted']);
        app(SensitiveAction::class)->confirm($request);

        return back()->with('status', __('admin.operations.purged', ['count' => $retention->purge()]));
    }

    /** @return Builder<AuditEntry> */
    private function query(Request $request): Builder
    {
        $data = $request->validate([
            'action' => 'nullable|string|max:100',
            'user_id' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
        ]);

        $zone = app(SiteDates::class)->timezone();

        return AuditEntry::query()
            ->when($data['action'] ?? null, fn ($query, $action) => TableQuery::whereContains($query, ['action'], $action))
            ->when($data['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when(
                $data['from'] ?? null,
                fn ($query, $date) => $query->where(
                    'created_at',
                    '>=',
                    CarbonImmutable::parse($date, $zone)->startOfDay()->utc(),
                ),
            )
            ->when(
                $data['to'] ?? null,
                fn ($query, $date) => $query->where(
                    'created_at',
                    '<',
                    CarbonImmutable::parse(CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString(), $zone)
                        ->startOfDay()
                        ->utc(),
                ),
            );
    }
}
