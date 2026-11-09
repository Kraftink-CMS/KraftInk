<?php

namespace App\Http\Controllers\Link;

use App\Kraft\Auth\InGameSignIn\InGameSignInChallenges;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Models\Servers\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkInGameSignInController
{
    public function __construct(
        private readonly InGameSignInPolicy $policy,
        private readonly InGameSignInChallenges $challenges,
    ) {
    }

    public function respond(Request $request): JsonResponse
    {
        $server = $request->attributes->get('link.server');
        abort_unless($server instanceof Server && $this->policy->trusts($server), 403);
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'uuid' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:60'],
            'platform' => ['required', 'string', 'max:20'],
            'action' => ['required', 'in:choose,reject,mute'],
            'choice' => ['required_if:action,choose', 'integer', 'between:10,99'],
        ]);
        $profile = $this->policy->adapter($server->game)?->inGameSignInProfile($data['uuid'], $data['username'], $data['platform']);
        abort_unless($profile, 422);
        $choice = isset($data['choice']) ? (int) $data['choice'] : null;
        $state = $this->challenges->respond($server, $data['id'], $profile, $data['action'], $choice);

        return response()->json(['success' => true, 'data' => ['state' => $state === 'unavailable' ? 'rejected' : $state]]);
    }
}
