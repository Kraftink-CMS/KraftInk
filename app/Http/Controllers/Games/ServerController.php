<?php

namespace App\Http\Controllers\Games;

use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Link\LinkController;
use App\Kraft\Audit;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Link\ConnectorInstance;
use App\Kraft\Settings;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\Transports\TransportCredentials;
use App\Kraft\Games\Transports\TransportRegistry;
use App\Kraft\System\SensitiveAction;
use App\Kraft\TextTranslations;
use App\Models\Servers\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(): View
    {
        $games = app(GameRegistry::class)->linkableGames();
        $transports = app(TransportRegistry::class);
        $serverTransports = [];
        $serverConnections = [];
        foreach ($games as $game => $definition) {
            $serverTransports[$game] = $transports->metadata($game);
            foreach ($definition['connections'] as $code => $label) {
                $metadata = $serverTransports[$game][$code] ?? [];
                $kraftlink = $code === 'kraftlink';
                $serverConnections[$game][$code] = [
                    'label' => $label,
                    'status' => $kraftlink || !empty($metadata['status']),
                    'commands' => $kraftlink || !empty($metadata['commands']),
                    'player_presence' => $kraftlink || !empty($metadata['player_presence']),
                    'managed' => $kraftlink || !empty($metadata['commands']),
                ];
            }
        }

        $servers = TableQuery::paginate(
            Server::query()->where('game', app(GameRegistry::class)->activeId())->orderBy('id'),
            ['name', 'host', 'enabled'],
            search: ['name', 'host'],
        );

        return view('admin.servers.index', [
            'servers' => $servers,
            'games' => $games,
            'serverTransports' => $serverTransports,
            'serverConnections' => $serverConnections,
            'serverSetupRequired' => $servers
                ->getCollection()
                ->mapWithKeys(fn (Server $server) => [$server->id => $transports->requiresSetup($server)]),
            'primaryServerId' => (int) app(Settings::class)->get('servers.primary', 0),
        ]);
    }

    public function link(Server $server, GameRegistry $games, TransportRegistry $transports): View
    {
        if (
            $server->game !== $games->activeId() ||
            !isset($games->linkableGames()[$server->game]['connections'][$server->connection])
        ) {
            abort(404);
        }

        return $server->connection === 'kraftlink'
            ? app(LinkController::class)->admin($server)
            : app(ServerTransportController::class)->index($server, $transports, $games);
    }

    public function store(Request $request, ?Server $server = null): RedirectResponse
    {
        $games = app(GameRegistry::class)->linkableGames();
        $game = $request->input('game');
        $connections = is_string($game) ? ($games[$game]['connections'] ?? []) : [];
        $request->mergeIfMissing([
            'show_port' => $server?->show_port ?? true,
            'show_players' => $server?->show_players ?? true,
        ]);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'game' => ['required', 'string', Rule::in(array_keys($games))],
            'host' => 'required|string|max:255|regex:/^[a-zA-Z0-9.:-]+$/',
            'port' => 'required|integer|between:1,65535',
            'show_port' => 'boolean',
            'show_players' => 'boolean',
            'connection' => ['required', 'string', Rule::in(array_keys($connections))],
            'enabled' => 'boolean',
        ]);
        $transports = app(TransportRegistry::class);
        $credentials = app(TransportCredentials::class);
        $metadata = $transports->metadata($data['game'])[$data['connection']] ?? [];
        $managed = $data['connection'] === 'kraftlink' || !empty($metadata['commands']);
        $sameConnection = $server && $server->game === $data['game'] && $server->connection === $data['connection'];
        if ($server?->connection === 'kraftlink' && !$sameConnection) {
            $data['token_hash'] = null;
        }
        $secret = null;
        if ($managed) {
            $request->validate(['transport_options' => 'prohibited', 'transport_secret' => 'prohibited']);
            if (!$sameConnection) {
                $data['transport_options'] = [];
                $data['secret'] = null;
            }
        } else {
            ['transport_options' => $data['transport_options'], 'transport_secret' => $secret] = $credentials->validate(
                $request,
                $metadata,
                $sameConnection ? $server : null,
            );
            if ($secret !== null) {
                $data['secret'] = $secret;
            } elseif (!$sameConnection || empty($metadata['secret'])) {
                $data['secret'] = null;
            }
        }
        $confirmationData = $data + ['transport_options' => $server?->transport_options ?? []];
        if (
            (!$managed || filled($server?->secret)) &&
            $credentials->requiresConfirmation($server, $confirmationData, $metadata, $secret)
        ) {
            app(SensitiveAction::class)->confirm($request);
        }
        $data['enabled'] =
            !empty($metadata['status']) && empty($metadata['commands'])
                ? $request->boolean('show_players')
                : $request->boolean('enabled');
        $data['show_port'] = $request->boolean('show_port');
        $data['show_players'] = $request->boolean('show_players');
        $fields = ['name' => ['max' => 100]];
        $translations = app(TextTranslations::class);
        $extra = $request->validate($translations->rules($fields));
        $data['translations'] = $translations->normalize(
            $extra['translations'] ?? [],
            $server?->translations ?? [],
            $fields,
        );
        $configureLink = $managed && (!$sameConnection || $transports->requiresSetup($server));
        $inGameSignIn = app(InGameSignInPolicy::class);
        $save = fn (): Server => DB::transaction(function () use ($server, $data, $inGameSignIn): Server {
            if ($server) {
                $inGameSignIn->ensureServerKeepsSignIn($server->id, $data);
                $server->update($data);
            } else {
                $server = Server::query()->create($data);
                if (!Server::query()->where('game', $server->game)->where('id', '!=', $server->id)->exists()) {
                    app(Settings::class)->set('servers.primary', $server->id);
                }
            }

            return $server;
        });
        $server = $server ? $inGameSignIn->withStableConfiguration($save) : $save();
        Audit::record('server.saved');

        if ($configureLink) {
            return redirect()->route('admin.servers.link.show', $server);
        }

        return back();
    }

    public function primary(Request $request, Server $server, Settings $settings): RedirectResponse
    {
        if ($server->game !== app(GameRegistry::class)->activeId()) {
            abort(404);
        }
        $remove = $request->isMethod('delete');
        if (!$remove || (int) $settings->get('servers.primary') === $server->id) {
            $settings->set('servers.primary', $remove ? null : $server->id);
            Audit::record('server.saved', ['id' => $server->id]);
        }

        return back();
    }

    public function destroy(Server $server): RedirectResponse
    {
        $inGameSignIn = app(InGameSignInPolicy::class);
        $inGameSignIn->withStableConfiguration(function () use ($server, $inGameSignIn): void {
            $inGameSignIn->ensureServerKeepsSignIn($server->id);
            $server->delete();
            app(ConnectorInstance::class)->forget($server);
        });
        Audit::record('server.deleted', ['id' => $server->id]);

        return back();
    }
}
