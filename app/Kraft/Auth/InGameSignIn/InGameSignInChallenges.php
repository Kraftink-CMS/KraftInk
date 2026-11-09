<?php

namespace App\Kraft\Auth\InGameSignIn;

use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InGameSignInChallenges
{
    private const int LIFETIME = 300;

    private const int PROMPT_LIFETIME = 60;

    public function __construct(
        private readonly InGameSignInPolicy $policy,
        private readonly InGameSignInAccounts $accounts,
    ) {
    }

    public function start(
        Server $server,
        string $name,
        string $browser,
        string $ip,
        ?string $previous,
        bool $acceptTerms,
        ?string $registrationCaptcha,
    ): array {
        $lookup = strtolower($name);

        return $this->update($server, function (array &$requests, int $now) use ($lookup, $browser, $ip, $previous, $acceptTerms, $registrationCaptcha): array {
            if ($previous !== null && isset($requests[$previous]) && $this->belongsTo($requests[$previous], $browser)) {
                $current = $requests[$previous];
                if (
                    $current['lookup'] === $lookup &&
                    $current['registration_captcha'] === $registrationCaptcha &&
                    ($current['state'] === 'waiting' || (
                        $current['state'] === 'approved' &&
                        ($current['approval']['user_id'] !== null || $current['terms_accepted'])
                    ))
                ) {
                    $current['terms_accepted'] = $acceptTerms || $current['terms_accepted'];
                    $requests[$previous] = $current;

                    return $this->present($current);
                }
                unset($requests[$previous]);
            }
            abort_if(count($requests) >= 250, 429, __('in-game-sign-in.busy'));
            $id = (string) Str::uuid();
            $challenge = [
                'id' => $id,
                'lookup' => $lookup,
                'browser' => hash('sha256', $browser),
                'ip' => hash_hmac('sha256', $ip, (string) config('app.key')),
                'expires' => $now + self::LIFETIME,
                'state' => 'waiting',
                'profile' => null,
                'terms_accepted' => $acceptTerms,
                'registration_captcha' => $registrationCaptcha,
            ];
            $requests[$id] = $this->preparePrompt($challenge, $now);

            return $this->present($requests[$id]);
        });
    }

    public function status(Server $server, string $id, string $browser): array
    {
        $challenge = $this->store()->get($this->queueKey($server), [])[$id] ?? null;
        if ($challenge === null || $challenge['expires'] <= now()->timestamp) {
            return ['id' => $id, 'number' => null, 'state' => 'expired', 'expires_in' => 0, 'resend_in' => 0];
        }
        abort_unless($this->belongsTo($challenge, $browser), 404);

        return $this->present($challenge);
    }

    public function resend(Server $server, string $id, string $browser): array
    {
        return $this->update($server, function (array &$requests, int $now) use ($id, $browser): array {
            $challenge = $this->forBrowser($requests, $id, $browser);
            abort_unless($challenge['state'] === 'waiting', 409);
            if ($challenge['resend'] <= $now) {
                $requests[$id] = $this->preparePrompt($challenge, $now);
            }

            return $this->present($requests[$id]);
        });
    }

    public function cancel(Server $server, string $id, string $browser): void
    {
        $this->update($server, function (array &$requests) use ($id, $browser): void {
            if (isset($requests[$id]) && $this->belongsTo($requests[$id], $browser)) {
                unset($requests[$id]);
            }
        });
    }

    public function poll(Server $server, array $profiles): array
    {
        if ($profiles === [] || !$this->store()->has($this->queueKey($server))) {
            return [];
        }
        $online = [];
        foreach ($profiles as $profile) {
            $lookup = strtolower($profile['username']);
            $online[$lookup] = array_key_exists($lookup, $online) && $online[$lookup] !== $profile ? null : $profile;
        }

        return $this->update($server, function (array &$requests, int $now) use ($server, $online): array {
            $pending = [];
            foreach ($requests as &$challenge) {
                $profile = $online[$challenge['lookup']] ?? null;
                if (
                    $challenge['state'] !== 'waiting' || !$challenge['prompt'] || $challenge['resend'] <= $now ||
                    !$profile || ($challenge['profile'] && $challenge['profile'] !== $profile) ||
                    $this->store()->has($this->muteKey($server, $profile)) ||
                    $this->store()->has($this->rejectKey($server, $profile, $challenge['ip']))
                ) {
                    continue;
                }
                if (!$challenge['delivered']) {
                    $cooldown = 'in-game-sign-in.cooldown.'.hash('sha256', $server->game.'|'.$profile['uuid']);
                    if (!$this->store()->add($cooldown, true, self::PROMPT_LIFETIME)) {
                        continue;
                    }
                    $challenge['delivered'] = true;
                    $challenge['resend'] = min($challenge['expires'], $now + self::PROMPT_LIFETIME);
                }
                $challenge['profile'] = $profile;
                $pending[] = [
                    'id' => $challenge['prompt'],
                    'uuid' => $profile['uuid'],
                    'choices' => $challenge['choices'],
                    'expires_in' => $challenge['resend'] - $now,
                ];
                if (count($pending) === 100) {
                    break;
                }
            }

            return $pending;
        });
    }

    public function respond(Server $server, string $prompt, array $profile, string $action, ?int $choice): string
    {
        return $this->update($server, function (array &$requests, int $now) use ($server, $prompt, $profile, $action, $choice): string {
            foreach ($requests as &$challenge) {
                if ($challenge['prompt'] !== $prompt || $challenge['profile'] !== $profile || $challenge['resend'] <= $now) {
                    continue;
                }
                if ($challenge['state'] !== 'waiting') {
                    abort_unless($challenge['response'] === [$action, $choice], 409);

                    return $challenge['state'];
                }
                $challenge['response'] = [$action, $choice];
                if ($action === 'mute') {
                    $this->store()->put($this->muteKey($server, $profile), true, now()->addDay());

                    return $challenge['state'] = 'muted';
                }
                if ($action === 'reject') {
                    $this->store()->put($this->rejectKey($server, $profile, $challenge['ip']), true, now()->addMinutes(15));

                    return $challenge['state'] = 'rejected';
                }
                if ($choice !== $challenge['number'] || !in_array($choice, $challenge['choices'], true)) {
                    return $challenge['state'] = 'rejected';
                }
                $approval = $this->accounts->approval($server->game, $profile);
                if (!$approval || ($approval['user_id'] === null && !$this->hasRegistrationProof($challenge))) {
                    return $challenge['state'] = 'unavailable';
                }
                $challenge['approval'] = $approval;

                return $challenge['state'] = 'approved';
            }

            throw new HttpException(404);
        });
    }

    public function finish(Server $server, string $id, string $browser, Request $request): User
    {
        $status = $this->status($server, $id, $browser);
        abort_unless($status['state'] === 'approved', 409);
        $email = null;
        if ($status['creating_account']) {
            if (is_string($request->input('email'))) {
                $request->merge(['email' => strtolower(trim($request->input('email')))]);
            }
            $data = $request->validate([
                'email' => [$status['needs_email'] ? 'required' : 'nullable', 'email', 'max:255', 'unique:users,email'],
            ]);
            $email = $data['email'] ?? null;
        }

        return $this->update($server, function (array &$requests) use ($server, $id, $browser, $email): User {
            $challenge = $this->forBrowser($requests, $id, $browser);
            abort_unless($challenge['state'] === 'approved', 409);
            $user = $this->policy->withStableConfiguration(function () use ($server, $challenge, $email): User {
                abort_unless($this->policy->trusts($server), 404);
                if ($challenge['approval']['user_id'] === null && !$this->hasRegistrationProof($challenge)) {
                    throw ValidationException::withMessages(['in_game_sign_in' => __('in-game-sign-in.restart')]);
                }
                $profile = $challenge['profile'];
                if (!$this->policy->adapter($server->game)?->inGameSignInProfile($profile['uuid'], $profile['username'], $profile['platform'])) {
                    throw ValidationException::withMessages(['in_game_sign_in' => __('in-game-sign-in.unavailable')]);
                }

                return $this->accounts->finish($server->game, $profile, $challenge['approval'], $email);
            });
            unset($requests[$id]);

            return $user;
        });
    }

    private function preparePrompt(array $challenge, int $now): array
    {
        $numbers = [];
        while (count($numbers) < 3) {
            $numbers[random_int(10, 99)] = true;
        }
        $numbers = array_keys($numbers);
        $challenge['number'] = $numbers[0];
        shuffle($numbers);
        $challenge['choices'] = $numbers;
        $challenge['resend'] = min($challenge['expires'], $now + self::PROMPT_LIFETIME);
        $challenge['prompt'] = (string) Str::uuid();
        $challenge['delivered'] = false;

        return $challenge;
    }

    private function present(array $challenge): array
    {
        $now = now()->timestamp;
        $creatingAccount = $challenge['state'] === 'approved' && $challenge['approval']['user_id'] === null;

        return [
            'id' => $challenge['id'],
            'number' => $challenge['number'],
            'state' => $challenge['state'],
            'expires_in' => max(0, $challenge['expires'] - $now),
            'resend_in' => max(0, $challenge['resend'] - $now),
            'creating_account' => $creatingAccount,
            'needs_email' => $creatingAccount && $this->accounts->requiresEmail(),
        ];
    }

    private function hasRegistrationProof(array $challenge): bool
    {
        return $challenge['terms_accepted'] &&
            $challenge['registration_captcha'] === $this->policy->registrationCaptchaFingerprint();
    }

    private function forBrowser(array $requests, string $id, string $browser): array
    {
        $challenge = $requests[$id] ?? null;
        abort_unless($challenge && $this->belongsTo($challenge, $browser), 404);

        return $challenge;
    }

    private function belongsTo(array $challenge, string $browser): bool
    {
        return hash_equals($challenge['browser'], hash('sha256', $browser));
    }

    private function update(Server $server, Closure $action): mixed
    {
        $store = $this->store();
        $key = $this->queueKey($server);
        try {
            return $store->lock($key.'.lock', 20)->block(2, function () use ($store, $key, $action): mixed {
                $original = $store->get($key, []);
                $now = now()->timestamp;
                $requests = array_filter($original, fn (array $challenge): bool => $challenge['expires'] > $now);
                $result = $action($requests, $now);
                if ($requests !== $original) {
                    if ($requests === []) {
                        $store->forget($key);
                    } else {
                        $store->put($key, $requests, self::LIFETIME);
                    }
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            throw new HttpException(503, __('in-game-sign-in.busy'));
        }
    }

    private function queueKey(Server $server): string
    {
        return 'in-game-sign-in.queue.'.hash('sha256', $server->id.'|'.$server->token_hash);
    }

    private function muteKey(Server $server, array $profile): string
    {
        return 'in-game-sign-in.mute.'.hash('sha256', $server->game.'|'.$profile['uuid']);
    }

    private function rejectKey(Server $server, array $profile, string $ip): string
    {
        return 'in-game-sign-in.reject.'.hash('sha256', $server->game.'|'.$profile['uuid'].'|'.$ip);
    }

    private function store(): Repository
    {
        return Cache::store('database');
    }
}
