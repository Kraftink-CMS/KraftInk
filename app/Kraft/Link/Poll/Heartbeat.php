<?php

namespace App\Kraft\Link\Poll;

use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Games\Contracts\ProvidesLinkHeartbeatData;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Link\Contracts\PollHandler;
use App\Models\Servers\Server;
use Illuminate\Support\Facades\Validator;

class Heartbeat implements PollHandler
{
    public function __construct(private GameRegistry $games, private InGameSignInPolicy $inGameSignIn)
    {
    }

    public function poll(Server $server, array $payload): array
    {
        $data = Validator::make($payload, [
            'protocol' => 'required|integer|in:1',
            'game' => 'required|string|max:50',
            'software' => 'required|string|max:100',
            'version' => 'required|string|max:100',
            'players' => 'present|array|max:2000',
            'players.*.uuid' => 'required|string|max:64',
            'players.*.name' => 'required|string|max:64',
            'players_online' => 'sometimes|required|integer|between:0,100000',
            'max_players' => 'required|integer|between:0,100000',
            'uptime_seconds' => 'required|integer|min:0',
            'identity_verification' => ['sometimes', 'boolean:strict'],
            'tps' => 'nullable|array|max:3',
            'tps.*' => 'numeric|min:0|max:1000',
            'memory' => 'required|array',
            'memory.used' => 'required|integer|min:0',
            'memory.max' => 'required|integer|min:0',
            'worlds' => 'nullable|array|max:1000',
            'worlds.*.name' => 'required|string|max:100',
            'worlds.*.players' => 'required|integer|min:0',
        ])->validate();
        abort_unless($server->game === $data['game'], 422);
        abort_if(isset($data['players_online']) && $data['players_online'] < count($data['players']), 422);
        $platform = request()->attributes->get('link.connector_platform');
        if ($platform !== null) {
            $data['connector_platform'] = $platform;
        }
        $server->forceFill(['status' => $data, 'last_seen_at' => now()])->save();
        $adapter = $this->games->find($server->game);
        $additional = $adapter instanceof ProvidesLinkHeartbeatData ? $adapter->linkHeartbeatData($server) : [];

        return [
            'protocol' => 1,
            'server_id' => $server->id,
            'time' => now()->toIso8601String(),
            'in_game_sign_in' => $this->inGameSignIn->trusts($server),
        ] + $additional + ['authme_registration' => false];
    }
}
