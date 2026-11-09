<?php

namespace KraftInk\Games\MinecraftBedrock\Http\Controllers;

use App\Kraft\Games\IdentityChallenges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KraftInk\Games\MinecraftBedrock\Adapters\BedrockAdapter;
use KraftInk\Games\MinecraftBedrock\Services\IdentityVerification;

class IdentityLinkController
{
    public function __invoke(Request $request, IdentityVerification $verification, IdentityChallenges $challenges, BedrockAdapter $adapter): JsonResponse
    {
        abort_unless($request->attributes->get('link.connector_platform') === 'nukkit' && $request->hasHeader('X-KraftLink-Instance'), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z2-7]{10}$/iD'],
            'uuid' => ['required', 'string', 'max:36'],
            'username' => ['required', 'string', 'max:24'],
        ]);
        $server = $request->attributes->get('link.server');
        $proof = $adapter->verifiedProfile($data['uuid'], $data['username']);
        abort_unless($challenges->redeem($verification, $server, $data['code'], $proof), 404);

        return response()->json(['success' => true, 'data' => ['verified' => true]]);
    }
}
