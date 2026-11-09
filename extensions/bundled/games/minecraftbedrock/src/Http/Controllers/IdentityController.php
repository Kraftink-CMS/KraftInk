<?php

namespace KraftInk\Games\MinecraftBedrock\Http\Controllers;

use App\Kraft\Audit;
use App\Kraft\Games\IdentityChallenges;
use App\Kraft\System\SensitiveAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\MinecraftBedrock\Services\IdentityVerification;

class IdentityController
{
    private const string SESSION_KEY = 'game_identity.minecraftbedrock';

    public function __construct(
        private readonly IdentityVerification $verification,
        private readonly IdentityChallenges $challenges,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canAuthenticateAsMember(), 403);
        abort_if($this->verification->configuration() === null, 404);

        $challenge = $request->session()->get(self::SESSION_KEY);
        $status = $challenge ? $this->challenges->status($this->verification, $user, $challenge['nonce'], $challenge['code']) : null;
        $server = $this->verification->selectedServer();
        $proof = $status['proof'] ?? null;

        return response()->view('minecraftbedrock::identity', [
            'server' => $server,
            'command' => $server && $status !== null && $proof === null ? $this->verification->command($challenge['code']) : null,
            'gamertag' => $proof['username'] ?? null,
            'nonce' => $proof === null ? null : $challenge['nonce'],
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function start(Request $request, SensitiveAction $sensitive): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAuthenticateAsMember(), 403);
        abort_unless($this->verification->ready(), 404);
        $sensitive->confirm($request);

        $nonce = Str::random(64);
        $challenge = $this->challenges->issue($this->verification, $user, $nonce);
        if ($previous = $request->session()->get(self::SESSION_KEY)) {
            $this->challenges->cancel($this->verification, $user, $previous['nonce'], $previous['code']);
        }
        $request->session()->put(self::SESSION_KEY, $challenge + ['nonce' => $nonce]);

        return redirect()->route('games.minecraftbedrock.identity.index');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAuthenticateAsMember(), 403);
        abort_if($this->verification->configuration() === null, 404);

        $challenge = $request->session()->get(self::SESSION_KEY);
        $nonce = $request->input('challenge');
        $redirect = redirect()->route('games.minecraftbedrock.identity.index');
        if (!$challenge || !is_string($nonce) || !hash_equals($challenge['nonce'], $nonce)) {
            return $redirect->withErrors(['challenge' => __('minecraftbedrock::messages.identity_stale')]);
        }
        try {
            $confirmed = $this->challenges->confirm($this->verification, $user, $nonce, $challenge['code']);
        } catch (ValidationException $exception) {
            return $redirect->withErrors($exception->errors());
        }
        $request->session()->forget(self::SESSION_KEY);
        if (!$confirmed) {
            return $redirect->withErrors(['challenge' => __('minecraftbedrock::messages.identity_stale')]);
        }
        Audit::record('games.identity_verified', ['game' => $this->verification->game()]);

        return redirect()->route('account.games.index')->with('status', __('minecraftbedrock::messages.identity_saved'));
    }
}
