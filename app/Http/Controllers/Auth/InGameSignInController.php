<?php

namespace App\Http\Controllers\Auth;

use App\Kraft\Auth\Captcha;
use App\Kraft\Auth\InGameSignIn\InGameSignInChallenges;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Auth\LoginManager;
use App\Models\Servers\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InGameSignInController
{
    public function __construct(
        private readonly InGameSignInPolicy $policy,
        private readonly InGameSignInChallenges $challenges,
    ) {
    }

    public function start(Request $request, Captcha $captcha): JsonResponse
    {
        $server = $this->server();
        $data = $request->validate([
            'username' => ['required', 'string', 'max:60'],
            'terms' => ['sometimes', 'accepted'],
        ]);
        $name = $this->policy->adapter($server->game)?->inGameSignInName($data['username']);
        if ($name === null) {
            throw ValidationException::withMessages(['username' => __('in-game-sign-in.invalid_name')]);
        }
        $action = $this->policy->captchaAction();
        $registrationCaptcha = $action === 'register' ? $this->policy->registrationCaptchaFingerprint() : null;
        $captcha->verify($request, $action);
        $browser = $request->session()->get('in_game_sign_in.browser') ?? Str::random(64);
        $challenge = $this->challenges->start(
            $server,
            $name,
            $browser,
            $request->ip() ?? '',
            $request->session()->get('in_game_sign_in.id'),
            array_key_exists('terms', $data),
            $registrationCaptcha,
        );
        $request->session()->put('in_game_sign_in', ['browser' => $browser, 'id' => $challenge['id']]);

        return $this->response($challenge);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        return $this->response($this->challenges->status($this->server(), $id, $this->browser($request, $id)));
    }

    public function resend(Request $request, string $id): JsonResponse
    {
        return $this->response($this->challenges->resend($this->server(), $id, $this->browser($request, $id)));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->challenges->cancel($this->server(), $id, $this->browser($request, $id));
        $request->session()->forget('in_game_sign_in');

        return $this->response(['state' => 'expired']);
    }

    public function finish(Request $request, LoginManager $login, string $id): RedirectResponse
    {
        $user = $this->challenges->finish($this->server(), $id, $this->browser($request, $id), $request);
        $request->session()->forget('in_game_sign_in');

        return $login->complete($request, $user, method: 'in_game');
    }

    private function server(): Server
    {
        $server = $this->policy->server();
        abort_unless($server, 404);

        return $server;
    }

    private function browser(Request $request, string $id): string
    {
        $browser = $request->session()->get('in_game_sign_in.browser');
        abort_unless(is_string($browser) && $request->session()->get('in_game_sign_in.id') === $id, 404);

        return $browser;
    }

    private function response(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}
