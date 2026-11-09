<?php

namespace KraftInk\Integrations\Steam\Avatars;

use App\Kraft\Auth\Avatars\AvatarIdentity;
use App\Kraft\Auth\Avatars\AvatarProvider;
use KraftInk\Integrations\Steam\Services\Profiles\SteamProfiles;

class SteamAvatar implements AvatarProvider
{
    public function id(): string
    {
        return 'steam';
    }

    public function metadata(): array
    {
        return [
            'name' => 'Steam',
            'description' => 'steam::messages.avatar',
            'website' => 'https://steamcommunity.com/',
        ];
    }

    public function url(AvatarIdentity $identity): ?string
    {
        if (!$identity->uuid) {
            return null;
        }
        $profile = app(SteamProfiles::class)->lookup($identity->uuid);
        $url = $profile['avatarfull'] ?? null;
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https' &&
            in_array(
                $parts['host'] ?? '',
                ['avatars.steamstatic.com', 'avatars.steamusercontent.com', 'steamcdn-a.akamaihd.net'],
                true,
            ) &&
            !isset($parts['user']) &&
            !isset($parts['pass'])
            ? $url
            : null;
    }
}
