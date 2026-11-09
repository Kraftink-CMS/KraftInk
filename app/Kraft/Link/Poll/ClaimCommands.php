<?php

namespace App\Kraft\Link\Poll;

use App\Kraft\Link\CommandQueue;
use App\Kraft\Link\Contracts\PollHandler;
use App\Models\Servers\Server;
use Illuminate\Support\Facades\Validator;

class ClaimCommands implements PollHandler
{
    public function __construct(private CommandQueue $queue)
    {
    }

    public function poll(Server $server, array $payload): array
    {
        $data = Validator::make($payload, [
            'protocol' => 'required|integer|in:1',
            'players' => 'present|array|max:2000',
            'players.*' => 'string|max:64',
            'player_names' => 'sometimes|array|max:2000',
            'player_names.*' => 'required|string|max:64',
            'limit' => 'required|integer|between:1,20',
        ])->validate();

        return ['commands' => $this->queue->claim($server, $data['players'], $data['limit'], $data['player_names'] ?? [])];
    }
}
