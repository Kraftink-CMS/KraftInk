<?php

namespace KraftInk\Integrations\Steam\OAuth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use KraftInk\Integrations\Steam\Services\Profiles\SteamIdentity;
use Throwable;
use xPaw\Steam\SteamOpenID as SteamProtocol;

class SteamOpenId implements Provider
{
    public function __construct(private array $configuration)
    {
    }

    public function redirect(): RedirectResponse
    {
        $return = $this->configuration['redirect'].'?state='.Str::random(64);
        request()->session()->put('steam.return_to', $return);

        return new RedirectResponse((new SteamProtocol($return))->GetAuthUrl());
    }

    public function user(): User
    {
        $return = request()->session()->pull('steam.return_to');
        $parameters = array_filter(
            request()->query(),
            static fn (mixed $value): bool => is_string($value) && strlen($value) <= 4096,
        );
        $nonce = $parameters['openid_response_nonce'] ?? '';
        if (
            !is_string($return) ||
            !hash_equals($return, $parameters['openid_return_to'] ?? '') ||
            strlen($nonce) > 256
        ) {
            throw new RuntimeException('Invalid Steam identity response.');
        }

        $protocol = new class($return, $parameters) extends SteamProtocol
        {
            public function SendSteamRequest(array $arguments): array
            {
                $response = Http::asForm()
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->withoutRedirecting()
                    ->post(self::SERVER, $arguments);
                $body = $response->body();

                return [$response->status(), strlen($body) <= 8192 ? $body : ''];
            }
        };
        $id = SteamIdentity::normalize($protocol->Validate());
        if (!Cache::add('steam-openid:'.hash('sha256', $nonce), true, 600)) {
            throw new RuntimeException('Steam rejected the identity response.');
        }
        $profile = [];
        if (!empty($this->configuration['client_secret'])) {
            try {
                $summary = Http::connectTimeout(5)
                    ->timeout(10)
                    ->withoutRedirecting()
                    ->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', [
                        'key' => $this->configuration['client_secret'],
                        'steamids' => $id,
                    ]);
                if ($summary->successful() && strlen($summary->body()) <= 65536) {
                    $candidate = $summary->json('response.players.0');
                    if (is_array($candidate) && ($candidate['steamid'] ?? '') === $id) {
                        $profile = $candidate;
                    }
                }
            } catch (Throwable) {
                $profile = [];
            }
        }
        $nickname = is_string($profile['personaname'] ?? null)
            ? mb_substr($profile['personaname'], 0, 60)
            : $this->fallbackNickname($id);

        $user = new User();

        return $user->setRaw(['steamid' => $id])->map([
            'id' => $id,
            'nickname' => $nickname,
            'name' => $nickname,
            'email' => null,
            'avatar' => null,
        ]);
    }

    private function fallbackNickname(string $id): string
    {
        $digest = hash_hmac('sha256', 'steam.nickname:'.$id, (string) config('app.key'));
        $number = hexdec(substr($digest, 0, 8)) % 100_000_000;

        return sprintf('steam-%08d', $number);
    }
}
