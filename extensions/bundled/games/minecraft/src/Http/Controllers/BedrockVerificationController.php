<?php

namespace KraftInk\Games\Minecraft\Http\Controllers;

use App\Kraft\Auth\AuthForms;
use App\Kraft\Auth\LoginManager;
use App\Kraft\Games\IdentityChallenges;
use App\Models\Accounts\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\Minecraft\Services\Auth\BedrockVerificationPolicy;

class BedrockVerificationController
{
    public function __construct(
        private readonly IdentityChallenges $challenges,
        private readonly BedrockVerificationPolicy $verification,
    ) {
    }

    public function show(Request $request): Response
    {
        [$pending, $user] = $this->pending($request);
        abort_unless($this->verification->available(), 404);

        $challenge = $pending['bedrock'] ?? null;
        $status = $challenge ? $this->challenges->status($this->verification, $user, $challenge['nonce'], $challenge['code']) : null;
        $proof = $status['proof'] ?? null;
        $code = $status !== null && !$proof ? $challenge['code'] : null;

        return AuthForms::render(
            $request,
            'minecraft::default.profile.bedrock',
            'minecraft::default.profile.partials.bedrock',
            __('minecraft::bedrock.title'),
            [
                'command' => $code === null ? null : $this->verification->command($code),
                'gamerTag' => $proof['username'] ?? null,
                'challengeNonce' => $proof ? $challenge['nonce'] : null,
                'checked' => $request->boolean('check'),
            ],
        )->header('Referrer-Policy', 'no-referrer');
    }

    public function start(Request $request): RedirectResponse
    {
        [$pending, $user] = $this->pending($request);
        abort_unless($this->verification->available(), 404);

        $nonce = Str::random(64);
        $challenge = $this->challenges->issue($this->verification, $user, $nonce, $pending['at'] + LoginManager::IDENTITY_PENDING_SECONDS);
        if ($previous = $pending['bedrock'] ?? null) {
            $this->challenges->cancel($this->verification, $user, $previous['nonce'], $previous['code']);
        }
        $request->session()->put('auth.identity_pending.bedrock', $challenge + ['nonce' => $nonce]);

        return redirect()->route('games.minecraft.public.bedrock.show');
    }

    public function complete(Request $request, LoginManager $login): RedirectResponse
    {
        [$pending, $user] = $this->pending($request);
        abort_unless($this->verification->available(), 404);

        $challenge = $pending['bedrock'] ?? null;
        $nonce = $request->input('challenge');
        $redirect = redirect()->route('games.minecraft.public.bedrock.show');
        if (!$challenge || !is_string($nonce) || !hash_equals($challenge['nonce'], $nonce)) {
            return $redirect->withErrors(['bedrock' => __('minecraft::bedrock.challenge_changed')]);
        }
        try {
            $confirmed = $this->challenges->confirm($this->verification, $user, $nonce, $challenge['code']);
        } catch (ValidationException $exception) {
            return $redirect->withErrors($exception->errors());
        }
        if (!$confirmed) {
            $request->session()->forget('auth.identity_pending.bedrock');

            return $redirect->withErrors(['bedrock' => __('minecraft::bedrock.challenge_changed')]);
        }

        return $login->complete($request, $user, $pending['remember'], false, 'game_identity');
    }

    private function pending(Request $request): array
    {
        $pending = $request->session()->get('auth.identity_pending');
        abort_unless($pending, 403);

        $user = User::query()->findOrFail($pending['id']);
        abort_unless($user->canAuthenticateAsMember(), 403);
        if (
            $pending['at'] <= time() - LoginManager::IDENTITY_PENDING_SECONDS ||
            ($pending['version'] ?? null) !== (int) $user->auth_session_version
        ) {
            $request->session()->forget('auth.identity_pending');
            $redirect = redirect()->route('login');
            if ($request->header('X-Kraft-Form') === 'true' && $request->expectsJson()) {
                throw new HttpResponseException($redirect->with('warning', __('minecraft::bedrock.login_expired')));
            }

            throw new HttpResponseException($redirect->withErrors([
                'bedrock' => __('minecraft::bedrock.login_expired'),
            ]));
        }

        return [$pending, $user];
    }
}
