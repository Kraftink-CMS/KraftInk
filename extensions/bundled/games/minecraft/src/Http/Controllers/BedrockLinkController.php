<?php

namespace KraftInk\Games\Minecraft\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KraftInk\Games\Minecraft\Services\Auth\BedrockChallenges;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BedrockLinkController
{
    public function verify(Request $request, BedrockChallenges $challenges): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z2-7]{10}$/iD'],
            'xuid' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'username' => ['required', 'string', 'max:17'],
        ]);
        $xuid = filter_var($data['xuid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($xuid === false) {
            throw new HttpException(422);
        }
        if (!$challenges->redeem($request->attributes->get('link.server'), $data['code'], $xuid, $data['username'])) {
            throw new HttpException(404);
        }

        return response()->json(['success' => true, 'data' => ['verified' => true]]);
    }
}
