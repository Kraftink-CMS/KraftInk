<?php

namespace KraftInk\Games\Minecraft\Services\Profiles;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\Minecraft\Adapters\MinecraftAdapter;

class MojangProfiles
{
    public function byUuid(string $uuid): array
    {
        $uuid = app(MinecraftAdapter::class)->normalizeUuid($uuid);

        return $this->lookup(
            'uuid.'.$uuid,
            'https://api.minecraftservices.com/minecraft/profile/lookup/'.$uuid,
            $uuid,
        );
    }

    public function byUsername(string $username): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]{3,16}$/D', $username)) {
            throw ValidationException::withMessages(['username' => __('minecraft::messages.profile_missing')]);
        }

        return $this->lookup(
            'name.'.strtolower($username),
            'https://api.mojang.com/users/profiles/minecraft/'.rawurlencode($username),
            expectedUsername: $username,
        );
    }

    private function lookup(
        string $key,
        string $url,
        ?string $expectedUuid = null,
        ?string $expectedUsername = null,
    ): array {
        return Cache::remember('minecraft.profiles.'.$key, 3600, function () use (
            $url,
            $expectedUuid,
            $expectedUsername,
        ): array {
            try {
                $response = Http::acceptJson()->connectTimeout(2)->timeout(5)->withoutRedirecting()->get($url);
            } catch (ConnectionException) {
                throw ValidationException::withMessages(['username' => __('minecraft::messages.profile_unavailable')]);
            }
            if (in_array($response->status(), [204, 404], true)) {
                throw ValidationException::withMessages(['username' => __('minecraft::messages.profile_missing')]);
            }
            if (!$response->successful() || strlen($response->body()) > 16384) {
                throw ValidationException::withMessages(['username' => __('minecraft::messages.profile_unavailable')]);
            }
            $data = $response->json();
            if (
                !is_array($data) ||
                !is_string($data['id'] ?? null) ||
                !is_string($data['name'] ?? null) ||
                !preg_match('/^[a-zA-Z0-9_]{3,16}$/D', $data['name'])
            ) {
                throw ValidationException::withMessages(['username' => __('minecraft::messages.profile_unavailable')]);
            }
            $uuid = MinecraftAdapter::parseUuid($data['id']);
            if ($uuid === null || ($expectedUsername !== null && strcasecmp($expectedUsername, $data['name']) !== 0)) {
                throw ValidationException::withMessages(['username' => __('minecraft::messages.profile_unavailable')]);
            }
            if ($expectedUuid && !hash_equals($expectedUuid, $uuid)) {
                throw ValidationException::withMessages(['uuid' => __('minecraft::messages.profile_mismatch')]);
            }

            return ['uuid' => $uuid, 'username' => $data['name']];
        });
    }
}
