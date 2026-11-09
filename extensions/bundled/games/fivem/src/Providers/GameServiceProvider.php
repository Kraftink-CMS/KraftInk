<?php

namespace KraftInk\Games\FiveM\Providers;

use App\Events\OAuthConfigurationValidating;
use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Settings;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use App\Kraft\Games\Transports\FiveMRconTransport;
use App\Kraft\Games\Transports\FiveMStatusTransport;
use KraftInk\Games\FiveM\Adapters\GameAdapter;
use KraftInk\Games\FiveM\OAuth\CfxOAuthAdapter;
use App\Kraft\Auth\Avatars\AvatarProviders;
use KraftInk\Integrations\Steam\Avatars\SteamAvatar;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(AvatarProviders::class)->register('fivem', 'steam', SteamAvatar::class, default: true);
        config([
            'games.adapters.fivem' => GameAdapter::class,
            'games.transports.fivem.http' => FiveMStatusTransport::class,
            'games.transports.fivem.fivem_rcon' => FiveMRconTransport::class,
            'oauth.adapters.cfx' => CfxOAuthAdapter::class,
            'oauth.providers.cfx' => [
                'name' => 'Cfx.re',
                'adapter' => 'cfx',
                'game' => 'fivem',
                'icon' => 'controller',
                'requires_https' => true,
                'credentials' => ['client_id' => false, 'client_secret' => false],
                'help' => 'fivem::messages.cfx_help',
            ],
        ]);
    }

    public function boot(GameRegistry $games, Settings $settings, OAuthRegistry $oauth): void
    {
        $this->app['events']->listen(OAuthConfigurationValidating::class, function (OAuthConfigurationValidating $event) use ($games, $settings, $oauth): void {
            if ($games->activeId() !== 'fivem' || $settings->get('games.fivem.authentication', 'open') !== 'verified') {
                return;
            }
            $adapter = $settings->get('games.fivem.identity_provider', 'cfx');
            foreach ($event->providers as $provider) {
                if (($oauth->metadata($provider)['adapter'] ?? null) === $adapter) {
                    return;
                }
            }
            throw ValidationException::withMessages(['oauth' => __('fivem::messages.setup_required')]);
        });
    }
}
