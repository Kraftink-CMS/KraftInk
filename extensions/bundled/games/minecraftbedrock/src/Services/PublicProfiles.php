<?php

namespace KraftInk\Games\MinecraftBedrock\Services;

use App\Kraft\Games\GameProfile;
use App\Kraft\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use JsonException;
use KraftInk\Games\MinecraftBedrock\Adapters\BedrockAdapter;

class PublicProfiles
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->get('games.minecraftbedrock.public_profiles', 'disabled') === 'enabled';
    }

    public function byGamertag(string $gamertag): GameProfile
    {
        $gamertag = trim($gamertag);
        $name = app(BedrockAdapter::class)->normalizeUuid($gamertag);
        if (mb_strlen($gamertag) > 16) {
            throw ValidationException::withMessages(['username' => __('minecraftbedrock::messages.public_gamertag_length')]);
        }

        $uuid = Cache::remember('minecraftbedrock.profile.'.hash('sha256', $name), 900, fn (): string => $this->lookup($gamertag));

        return new GameProfile($uuid, $gamertag);
    }

    private function lookup(string $gamertag): string
    {
        try {
            $response = Http::acceptJson()->connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->get('https://api.geysermc.org/v2/xbox/xuid/'.rawurlencode($gamertag));
            if (in_array($response->status(), [204, 404], true)) {
                throw ValidationException::withMessages(['username' => __('minecraftbedrock::messages.public_profile_missing')]);
            }
            if (!$response->successful() || strlen($response->body()) > 4096) {
                throw ValidationException::withMessages(['username' => __('minecraftbedrock::messages.public_profiles_unavailable')]);
            }
            $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (ConnectionException|JsonException) {
            throw ValidationException::withMessages(['username' => __('minecraftbedrock::messages.public_profiles_unavailable')]);
        }
        if ($data === []) {
            throw ValidationException::withMessages(['username' => __('minecraftbedrock::messages.public_profile_missing')]);
        }
        $xuid = is_array($data) ? ($data['xuid'] ?? null) : null;
        $uuid = is_string($xuid) || is_int($xuid) ? $this->uuidFromXuid((string) $xuid) : null;
        if ($uuid === null) {
            throw ValidationException::withMessages(['username' => __('minecraftbedrock::messages.public_profiles_unavailable')]);
        }

        return $uuid;
    }

    private function uuidFromXuid(string $xuid): ?string
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $xuid) ||
            (strlen($xuid) === 20 && strcmp($xuid, '18446744073709551615') > 0)) {
            return null;
        }

        $high = 0;
        $low = 0;
        foreach (str_split($xuid) as $digit) {
            $low = $low * 10 + (int) $digit;
            $high = $high * 10 + ($low >> 32);
            $low &= 0xFFFFFFFF;
        }

        return sprintf('0000000000000000%08x%08x', $high, $low);
    }
}
