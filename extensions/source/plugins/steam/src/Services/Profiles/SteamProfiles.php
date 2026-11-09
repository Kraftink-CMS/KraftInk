<?php

namespace KraftInk\Integrations\Steam\Services\Profiles;

use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Secrets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SteamProfiles
{
    public function key(): ?string
    {
        foreach (app(OAuthRegistry::class)->enabledForAdapter('steam') as $provider) {
            $key = app(Secrets::class)->get('oauth.'.$provider.'.client_secret');
            if ($key !== '') {
                return $key;
            }
        }

        return null;
    }

    public function lookup(string $id): array
    {
        $id = SteamIdentity::normalize($id);
        $key = $this->key();
        if (!$key) {
            throw ValidationException::withMessages(['username' => __('steam::messages.profile_unavailable')]);
        }

        return Cache::remember('steam-profile:'.hash('sha256', $key.':'.$id), 900, function () use (
            $key,
            $id,
        ): array {
            try {
                $response = Http::connectTimeout(5)
                    ->timeout(10)
                    ->withoutRedirecting()
                    ->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', [
                        'key' => $key,
                        'steamids' => $id,
                    ]);
                $profile = strlen($response->body()) <= 65536 ? $response->json('response.players.0') : null;
                if (
                    !$response->successful() ||
                    !is_array($profile) ||
                    ($profile['steamid'] ?? '') !== $id ||
                    !is_string($profile['personaname'] ?? null) ||
                    mb_strlen($profile['personaname']) > 60
                ) {
                    throw new RuntimeException('Invalid Steam public profile.');
                }

                return $profile;
            } catch (Throwable) {
                throw ValidationException::withMessages(['username' => __('steam::messages.profile_unavailable')]);
            }
        });
    }
}
