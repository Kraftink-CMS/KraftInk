<?php

namespace App\Kraft\Link\Poll;

use App\Kraft\Auth\InGameSignIn\InGameSignInChallenges;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Link\Contracts\PollHandler;
use App\Models\Servers\Server;
use Illuminate\Support\Facades\Validator;

class InGameSignIn implements PollHandler
{
    public function __construct(private InGameSignInPolicy $policy, private InGameSignInChallenges $challenges)
    {
    }

    public function poll(Server $server, array $payload): array
    {
        abort_unless($this->policy->trusts($server), 403);
        $data = Validator::make($payload, [
            'players' => ['present', 'array', 'max:2000'],
            'players.*' => ['array:uuid,username,platform'],
            'players.*.uuid' => ['required', 'string', 'max:64'],
            'players.*.username' => ['required', 'string', 'max:60'],
            'players.*.platform' => ['required', 'string', 'max:20'],
        ])->validate();
        $adapter = $this->policy->adapter($server->game);
        $profiles = [];
        foreach ($data['players'] as $player) {
            $profile = $adapter?->inGameSignInProfile($player['uuid'], $player['username'], $player['platform']);
            if ($profile) {
                $profiles[] = $profile;
            }
        }

        return ['requests' => $this->challenges->poll($server, $profiles)];
    }
}
