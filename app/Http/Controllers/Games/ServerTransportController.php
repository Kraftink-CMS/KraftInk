<?php

namespace App\Http\Controllers\Games;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Games\GameRegistry;
use App\Exceptions\TransportFailure;
use App\Kraft\Games\Transports\TransportRegistry;
use App\Kraft\Games\Transports\TransportCredentials;
use App\Models\Servers\Server;
use App\Kraft\Link\CommandQueue;
use App\Kraft\System\SensitiveAction;
use App\Kraft\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ServerTransportController extends Controller
{
    public function index(Server $server, TransportRegistry $transports, GameRegistry $games): View
    {
        if ($server->game !== $games->activeId()) {
            abort(404);
        }
        $transport = $transports->get($server);
        if (!($transport && !empty($transport->metadata()['commands']))) {
            abort(404);
        }

        return view('admin.servers.transport.index', [
            'server' => $server,
            'metadata' => $transport->metadata(),
            'configurationRequired' => $transports->requiresSetup($server),
            'connectionLabel' => $games->all()[$server->game]['connections'][$server->connection],
            'commands' => TableQuery::paginate(
                $server->commands()->latest('created_at'),
                ['command', 'status', 'attempts'],
                search: ['command'],
            ),
        ]);
    }

    public function configure(
        Request $request,
        Server $server,
        TransportRegistry $transports,
        GameRegistry $games,
    ): RedirectResponse {
        if ($server->game !== $games->activeId()) {
            abort(404);
        }
        $metadata = $transports->get($server)?->metadata() ?? [];
        if (empty($metadata['commands'])) {
            abort(404);
        }
        $credentials = app(TransportCredentials::class);
        ['transport_options' => $options, 'transport_secret' => $secret] = $credentials->validate(
            $request,
            $metadata,
            $server,
        );
        if (
            $credentials->requiresConfirmation(
                $server,
                [
                    'game' => $server->game,
                    'connection' => $server->connection,
                    'host' => $server->host,
                    'port' => $server->port,
                    'transport_options' => $options,
                ],
                $metadata,
                $secret,
            )
        ) {
            app(SensitiveAction::class)->confirm($request);
        }
        $server->transport_options = $options;
        if ($secret !== null) {
            $server->secret = $secret;
        }
        $server->save();
        Audit::record('server.saved', ['id' => $server->id]);

        return redirect()->route('admin.servers.link.show', $server)->with('status', __('common.forms.saved'));
    }

    public function enqueue(
        Request $request,
        Server $server,
        TransportRegistry $transports,
        GameRegistry $games,
        SensitiveAction $security,
        CommandQueue $queue,
    ): RedirectResponse {
        if (
            !(
                $server->game === $games->activeId() &&
                $server->enabled &&
                !$transports->requiresSetup($server) &&
                !empty($transports->get($server)?->metadata()['commands'])
            )
        ) {
            throw new HttpException(422);
        }
        $data = $request->validate([
            'command' => ['required', 'string', 'max:2048', 'not_regex:/[\x00\r\n]/'],
            'idempotency_key' => 'required|uuid',
        ]);
        $security->confirm($request);
        $id = $queue->enqueue(
            $server,
            $data['command'],
            idempotencyKey: 'server:'.$server->id.':admin:'.$data['idempotency_key'],
        );
        Audit::record('link.command_queued', ['id' => $id, 'server' => $server->id]);

        return back()->with('status', __('admin.servers.link.queued'));
    }

    public function check(Server $server, TransportRegistry $transports, GameRegistry $games): RedirectResponse
    {
        if ($server->game !== $games->activeId()) {
            abort(404);
        }
        $transport = $transports->get($server);
        if (!($transport && !empty($transport->metadata()['status']) && !$transports->requiresSetup($server))) {
            throw new HttpException(422);
        }
        try {
            $status = $transport->status($server);
            $server->forceFill(['status' => $status, 'last_seen_at' => now()])->save();
        } catch (Throwable $exception) {
            return back()->withErrors([
                'server' => TransportFailure::label($exception instanceof TransportFailure ? $exception->reason : null),
            ]);
        }

        return back()->with('status', __('admin.servers.transport.status_updated'));
    }
}
