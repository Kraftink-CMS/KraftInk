<?php

namespace KraftInk\Games\Minecraft\Http\Controllers;

use App\Kraft\Games\IdentityChallenges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KraftInk\Games\Minecraft\Services\Auth\BedrockVerificationPolicy;
use KraftInk\Games\Minecraft\Services\Profiles\FloodgateProfiles;

class BedrockLinkController
{
    public function verify(Request $request, IdentityChallenges $challenges, BedrockVerificationPolicy $verification, FloodgateProfiles $profiles): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z2-7]{10}$/iD'],
            'xuid' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'username' => ['required', 'string', 'max:17'],
        ]);
        $xuid = filter_var($data['xuid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($xuid === false, 422);
        $uuid = str_pad(dechex($xuid), 32, '0', STR_PAD_LEFT);
        abort_unless($profiles->validName($data['username']) && $profiles->isFloodgateUuid($uuid), 422);
        $proof = ['uuid' => $uuid, 'username' => $data['username']];
        abort_unless($challenges->redeem($verification, $request->attributes->get('link.server'), $data['code'], $proof), 404);

        return response()->json(['success' => true, 'data' => ['verified' => true]]);
    }
}
