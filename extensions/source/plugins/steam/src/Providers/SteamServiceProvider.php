<?php

namespace KraftInk\Integrations\Steam\Providers;

use App\Events\OAuthConfigurationValidating;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use KraftInk\Integrations\Steam\Adapters\SteamGameAdapter;
use KraftInk\Integrations\Steam\OAuth\SteamOAuthAdapter;
use App\Kraft\OAuth\OAuthRegistry;

class SteamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'oauth.adapters.steam' => SteamOAuthAdapter::class,
            'oauth.providers.steam' => [
                'name' => 'Steam',
                'adapter' => 'steam',
                'icon' => 'steam',
                'documentation' => 'https://partner.steamgames.com/doc/features/auth',
                'console' => 'https://steamcommunity.com/dev/apikey',
                'help' => 'steam::messages.oauth',
                'credentials' => ['client_id' => false, 'client_secret' => false],
                'secret_label' => 'steam::messages.api_key',
            ],
        ]);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'steam');
        Event::listen(OAuthConfigurationValidating::class, function (OAuthConfigurationValidating $event): void {
            $registry = app(GameRegistry::class);
            $id = $registry->activeId();
            if (
                !($registry->find($id) instanceof SteamGameAdapter) ||
                app(Settings::class)->get('games.'.$id.'.authentication', 'open') !== 'steam'
            ) {
                return;
            }
            $definitions = app(OAuthRegistry::class)->definitions();
            foreach ($event->providers as $provider) {
                if (($definitions[$provider]['adapter'] ?? null) === 'steam') {
                    return;
                }
            }
            throw ValidationException::withMessages(['oauth' => __('steam::messages.provider_required')]);
        });
    }
}
