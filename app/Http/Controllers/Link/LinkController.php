<?php

namespace App\Http\Controllers\Link;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Link\CommandQueue;
use App\Kraft\Link\Poll\ClaimCommands;
use App\Kraft\Link\Poll\Heartbeat;
use App\Kraft\Settings;
use App\Models\Servers\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Kraft\System\SensitiveAction;
use App\Kraft\Games\GameRegistry;
use Random\RandomException;

class LinkController extends Controller
{
    public function heartbeat(Request $request, Heartbeat $heartbeat): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $heartbeat->poll($request->attributes->get('link.server'), $request->all()),
        ]);
    }

    public function claim(Request $request, ClaimCommands $commands): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $commands->poll($request->attributes->get('link.server'), $request->all()),
        ]);
    }

    public function acknowledge(Request $request, CommandQueue $queue, string $id): JsonResponse
    {
        $data = $request->validate([
            'lease' => 'required|string|min:20|max:64',
            'state' => ['required', Rule::in(['executed', 'failed', 'uncertain', 'deferred'])],
            'error' => 'nullable|string|max:1000',
        ]);
        $queue->acknowledge(
            $request->attributes->get('link.server'),
            $id,
            $data['lease'],
            $data['state'],
            $data['error'] ?? null,
        );

        return response()->json(['success' => true, 'data' => ['ok' => true]]);
    }

    public function admin(Server $server): View
    {
        return view('admin.servers.link.show', [
            'server' => $server,
            'commands' => TableQuery::paginate(
                $server->commands()->latest('created_at'),
                ['command', 'status', 'attempts'],
                search: ['command'],
            ),
        ]);
    }

    /**
     * @throws RandomException
     */
    public function token(Server $server, Settings $settings, Request $request): RedirectResponse
    {
        $game = app(GameRegistry::class)->linkableGames()[$server->game] ?? [];
        if (!isset($game['connections']['kraftlink'])) {
            throw new HttpException(422);
        }
        app(SensitiveAction::class)->confirm($request);
        $token = bin2hex(random_bytes(32));
        $server->forceFill(['token_hash' => hash('sha256', $token)])->save();
        if ($request->boolean('default')) {
            $settings->set('link.default_server', $server->id);
        } elseif ($settings->get('link.default_server') == $server->id) {
            $settings->set('link.default_server', null);
        }
        Audit::record('link.token_rotated', ['server' => $server->id]);

        return back()->with('link.token', $token)->with('status', __('admin.servers.link.token_created'));
    }

    public function enqueue(Request $request, Server $server, CommandQueue $queue): RedirectResponse
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:2048', 'not_regex:/[\r\n]/'],
            'player' => [
                'nullable',
                'string',
                'max:64',
                'not_regex:/[\x00-\x1F\x7F]/',
                Rule::requiredIf($request->boolean('requires_online')),
            ],
            'requires_online' => 'boolean',
        ]);
        if (!($server->connection === 'kraftlink' && $server->enabled)) {
            throw new HttpException(422);
        }
        $id = $queue->enqueue($server, $data['command'], $data['player'] ?? null, $request->boolean('requires_online'));
        Audit::record('link.command_queued', ['id' => $id, 'server' => $server->id]);

        return back()->with('status', __('admin.servers.link.queued'));
    }
}
