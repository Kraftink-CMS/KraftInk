<?php

namespace KraftInk\Games\Minecraft\Http\Controllers;

use App\Models\Servers\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KraftInk\Games\Minecraft\Services\Auth\AuthMeRegistrationPolicy;
use KraftInk\Games\Minecraft\Services\Auth\AuthMeRegistrations;

class AuthMeRegistrationController
{
    public function __invoke(Request $request, AuthMeRegistrationPolicy $policy, AuthMeRegistrations $registrations): JsonResponse
    {
        $server = $request->attributes->get('link.server');
        abort_unless($server instanceof Server && $policy->allowsTransport($request) && $policy->trusts($server), 403);
        $data = $request->validate([
            'uuid' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{3,16}$/D'],
            'password' => ['required', 'string', 'max:255'],
            'authme_registered_at' => ['required', 'integer', 'min:1'],
        ]);
        $registrations->register($server, $data['uuid'], $data['username'], $data['password'], (int) $data['authme_registered_at']);

        return response()->json(['success' => true, 'data' => ['state' => 'created']], 201, ['Cache-Control' => 'private, no-store']);
    }
}
