<?php

namespace App\Kraft\Auth;

use App\Kraft\Auth\Avatars\AvatarProvider;
use App\Kraft\Auth\Avatars\AvatarProviders;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;

class AvatarPolicy
{
    public function __construct(private Settings $settings, private AvatarProviders $providers)
    {
    }

    public function providerId(?string $game = null): ?string
    {
        $game ??= app(GameRegistry::class)->activeId();
        if (!$game) {
            return null;
        }
        $provider = $this->settings->get('avatars.providers.'.$game);

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    public function provider(?string $game = null): ?AvatarProvider
    {
        $game ??= app(GameRegistry::class)->activeId();
        $id = $this->providerId($game);

        return $game && $id ? $this->providers->get($game, $id) : null;
    }

    public function allowsUploads(): bool
    {
        return $this->settings->get('avatars.allow_uploads', true) === true;
    }
}
