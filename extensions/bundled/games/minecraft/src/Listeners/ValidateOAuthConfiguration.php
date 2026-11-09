<?php

namespace KraftInk\Games\Minecraft\Listeners;

use App\Events\OAuthConfigurationValidating;
use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Settings;
use Illuminate\Validation\ValidationException;

class ValidateOAuthConfiguration
{
    public function handle(OAuthConfigurationValidating $event): void
    {
        if (
            app(GameRegistry::class)->activeId() !== 'minecraft' ||
            app(Settings::class)->get('games.minecraft.authentication', 'open') !== 'premium'
        ) {
            return;
        }
        $registry = app(OAuthRegistry::class);
        foreach ($event->providers as $provider) {
            if (($registry->metadata($provider)['adapter'] ?? null) === 'minecraft') {
                return;
            }
        }
        throw ValidationException::withMessages(['oauth' => __('minecraft::messages.premium_provider_required')]);
    }
}
