<?php

namespace App\Http\Controllers\Link;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Link\DeliveryMonitor;
use App\Kraft\Settings;
use App\Models\Servers\Server;
use App\Models\Servers\LinkCommand;
use App\Kraft\Audit;
use App\Kraft\System\SensitiveAction;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LinkDeliveryController extends Controller
{
    public function index(Request $request, DeliveryMonitor $monitor): View
    {
        $sources = $monitor->sources($request->user());
        if ($sources === []) {
            throw new HttpException(403);
        }
        $statuses = [];
        foreach (['pending', 'processing', 'delivered', 'failed', 'cancelled'] as $state) {
            $statuses[$state] = __(
                $state === 'failed'
                    ? 'admin.servers.deliveries.failed_only'
                    : 'admin.servers.deliveries.states.'.$state,
            );
        }
        foreach ($sources as $source) {
            $statuses += $source['statuses'];
        }
        $filters = $request->validate([
            'source' => ['nullable', 'string', Rule::in(array_keys($sources))],
            'status' => ['nullable', Rule::in(['attention', ...array_keys($statuses)])],
            'search' => ['nullable', 'string', 'max:180'],
            'server_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $game = app(GameRegistry::class)->activeId();
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $defaultServer = (int) $settings->get('link.default_server', 0);
        $deliveries = $monitor->paginate($sources, $filters, $game, $defaultServer);
        $servers = Server::query()->where('game', $game)->orderBy('name')->get()->keyBy('id');
        foreach ($deliveries as $delivery) {
            $delivery->details = $sources[$delivery->source]['details']($delivery);
            $delivery->pending_reason =
                $delivery->status === 'pending' && !$delivery->needs_attention
                    ? $monitor->pendingReason($delivery, $servers->get($delivery->server_id ?: $defaultServer))
                    : null;
        }

        return view('admin.servers.deliveries.index', [
            'deliveries' => $deliveries,
            'sources' => $sources,
            'deliveryStatuses' => $statuses,
            'filters' => $filters,
            'servers' => $servers,
            'defaultServer' => $defaultServer,
        ]);
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $request->validate(['confirm_cancel' => ['accepted']]);
        app(SensitiveAction::class)->confirm($request);
        DB::transaction(function () use ($id): void {
            $command = LinkCommand::query()
                ->whereKey($id)
                ->whereHas('server', fn ($query) => $query->where('game', app(GameRegistry::class)->activeId()))
                ->lockForUpdate()
                ->first();
            if (
                !$command ||
                $command->status !== 'pending' ||
                $command->executed_at ||
                $command->server
                    ->commandAttempts()
                    ->where('command_id', LinkCommand::DELIVERY_PREFIX.$id)
                    ->exists()
            ) {
                throw new HttpException(409, __('admin.servers.deliveries.cancel_unavailable'));
            }
            $command->update(['status' => 'cancelled']);
            Audit::record('link.delivery_cancelled', ['source' => 'core', 'id' => $id]);
        }, 3);

        return back()->with('status', __('admin.servers.deliveries.cancelled'));
    }
}
