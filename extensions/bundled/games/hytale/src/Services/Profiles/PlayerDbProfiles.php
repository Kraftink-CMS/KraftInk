<?php

namespace KraftInk\Games\Hytale\Services\Profiles;

use App\Kraft\Settings;
use App\Kraft\System\OperationLock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\Hytale\Adapters\HytaleAdapter;

class PlayerDbProfiles
{
    public function enabled(): bool
    {
        return app(Settings::class)->get('games.hytale.public_profiles', 'disabled') === 'enabled';
    }

    public function lookup(string $identifier): array
    {
        if (!$this->enabled()) {
            throw ValidationException::withMessages(['username' => __('hytale::messages.profiles_disabled')]);
        }
        $uuid = HytaleAdapter::parseUuid($identifier);
        if ($uuid === null && !preg_match('/^[a-zA-Z0-9_]{3,16}$/D', $identifier)) {
            throw ValidationException::withMessages(['username' => __('hytale::messages.profile_invalid')]);
        }
        $identifier = $uuid ?? $identifier;

        return app(OperationLock::class)->shared(
            fn () => Cache::remember(
                'hytale.public-profiles.v1.'.hash('sha256', strtolower($identifier)),
                3600,
                fn () => $this->fetch($identifier, $uuid),
            ),
        );
    }

    private function fetch(string $identifier, ?string $expectedUuid): array
    {
        try {
            $response = Http::acceptJson()
                ->withUserAgent('KraftInk/'.config('manifest.version').' (Hytale public profiles)')
                ->connectTimeout(2)
                ->timeout(5)
                ->withoutRedirecting()
                ->get('https://playerdb.co/api/player/hytale/'.rawurlencode($identifier));
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['username' => __('hytale::messages.profile_unavailable')]);
        }
        if (in_array($response->status(), [204, 404], true)) {
            throw ValidationException::withMessages(['username' => __('hytale::messages.profile_missing')]);
        }
        if (!$response->successful() || strlen($response->body()) > 16384) {
            throw ValidationException::withMessages(['username' => __('hytale::messages.profile_unavailable')]);
        }
        $data = $response->json();
        $profile = is_array($data) ? $data['data']['player'] ?? null : null;
        if (
            !is_array($profile) ||
            ($data['success'] ?? null) !== true ||
            ($data['code'] ?? null) !== 'player.found' ||
            !is_string($profile['id'] ?? null) ||
            !is_string($profile['username'] ?? null) ||
            !preg_match('/^[a-zA-Z0-9_]{3,16}$/D', $profile['username'])
        ) {
            throw ValidationException::withMessages(['username' => __('hytale::messages.profile_unavailable')]);
        }
        $uuid = HytaleAdapter::parseUuid($profile['id']);
        if ($uuid === null) {
            throw ValidationException::withMessages(['username' => __('hytale::messages.profile_unavailable')]);
        }
        if (
            ($expectedUuid !== null && !hash_equals($expectedUuid, $uuid)) ||
            ($expectedUuid === null && strcasecmp($identifier, $profile['username']) !== 0)
        ) {
            throw ValidationException::withMessages(['username' => __('hytale::messages.profile_mismatch')]);
        }

        return ['uuid' => $uuid, 'username' => $profile['username']];
    }
}
