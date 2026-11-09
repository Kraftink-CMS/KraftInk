<?php

namespace KraftInk\Games\Minecraft\Services\Profiles;

use App\Kraft\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\Minecraft\Adapters\MinecraftAdapter;

class FloodgateProfiles
{
    public function enabled(): bool
    {
        return app(Settings::class)->get('games.minecraft.floodgate', 'disabled') === 'enabled';
    }

    public function prefix(): string
    {
        $prefix = app(Settings::class)->get('games.minecraft.floodgate_prefix', '.');

        return in_array($prefix, ['.', '+', '-'], true) ? $prefix : '.';
    }

    public function isBedrockName(string $name): bool
    {
        return $this->enabled() && str_starts_with($name, $this->prefix());
    }

    public function normalizeName(string $name): string
    {
        return $this->isBedrockName($name) ? str_replace(' ', '_', $name) : $name;
    }

    public function validName(string $name): bool
    {
        return $this->isBedrockName($name) &&
            preg_match('/^'.preg_quote($this->prefix(), '/').'[A-Za-z0-9_]{1,16}$/D', $name) === 1;
    }

    public function isFloodgateUuid(string $uuid): bool
    {
        $uuid = strtolower(str_replace('-', '', $uuid));

        return preg_match('/^0{16}[0-7][0-9a-f]{15}$/D', $uuid) === 1 && substr($uuid, 16) !== str_repeat('0', 16);
    }

    public function byUsername(string $name, ?string $expectedUuid = null): array
    {
        $name = $this->normalizeName($name);
        if (!$this->validName($name)) {
            throw ValidationException::withMessages(['username' => __('minecraft::messages.floodgate_invalid_name')]);
        }
        $profile = Cache::remember(
            'minecraft.floodgate.'.hash('sha256', $this->prefix().':'.strtolower($name)),
            3600,
            function () use ($name): array {
                try {
                    $response = Http::acceptJson()
                        ->connectTimeout(2)
                        ->timeout(5)
                        ->withoutRedirecting()
                        ->get('https://api.geysermc.org/v2/utils/uuid/bedrock_or_java/'.rawurlencode($name), [
                            'prefix' => $this->prefix(),
                        ]);
                } catch (ConnectionException) {
                    throw ValidationException::withMessages([
                        'username' => __('minecraft::messages.profile_unavailable'),
                    ]);
                }
                if (in_array($response->status(), [204, 404], true)) {
                    throw ValidationException::withMessages([
                        'username' => __('minecraft::messages.floodgate_profile_missing'),
                    ]);
                }
                if (!$response->successful() || strlen($response->body()) > 16384) {
                    throw ValidationException::withMessages([
                        'username' => __('minecraft::messages.profile_unavailable'),
                    ]);
                }
                $data = $response->json();
                if (!is_array($data) || !is_string($data['id'] ?? null) || !is_string($data['name'] ?? null)) {
                    throw ValidationException::withMessages([
                        'username' => __('minecraft::messages.profile_unavailable'),
                    ]);
                }
                $uuid = MinecraftAdapter::parseUuid($data['id']);
                if ($uuid === null) {
                    throw ValidationException::withMessages([
                        'username' => __('minecraft::messages.profile_unavailable'),
                    ]);
                }
                $returned = $this->normalizeName($data['name']);
                if (
                    !$this->isFloodgateUuid($uuid) ||
                    !$this->validName($returned) ||
                    strcasecmp($name, $returned) !== 0
                ) {
                    throw ValidationException::withMessages(['username' => __('minecraft::messages.profile_mismatch')]);
                }

                return ['uuid' => $uuid, 'username' => $returned];
            },
        );
        if (
            $expectedUuid !== null &&
            !hash_equals(app(MinecraftAdapter::class)->normalizeUuid($expectedUuid), $profile['uuid'])
        ) {
            throw ValidationException::withMessages(['uuid' => __('minecraft::messages.profile_mismatch')]);
        }

        return $profile;
    }
}
