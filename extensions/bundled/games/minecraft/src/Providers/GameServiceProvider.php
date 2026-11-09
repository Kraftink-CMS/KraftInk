<?php

namespace KraftInk\Games\Minecraft\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use KraftInk\Games\Minecraft\Adapters\MinecraftAdapter;
use KraftInk\Games\Minecraft\Listeners\ValidateOAuthConfiguration;
use KraftInk\Games\Minecraft\OAuth\MinecraftOAuthAdapter;
use KraftInk\Games\Minecraft\Transports\RconTransport;
use KraftInk\Games\Minecraft\Transports\StatusTransport;
use App\Events\OAuthConfigurationValidating;
use App\Kraft\Auth\Avatars\AvatarProviders;
use App\Kraft\Extensions\SlotRegistry;
use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\OAuthRegistry;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $avatars = app(AvatarProviders::class);
        foreach (
            [
                'crafthead' => [
                    'name' => 'Crafthead',
                    'website' => 'https://crafthead.net/',
                    'template' => 'https://crafthead.net/avatar/{identity}/128',
                ],
                'mc-heads' => [
                    'name' => 'MCHeads',
                    'website' => 'https://mc-heads.net/',
                    'template' => 'https://mc-heads.net/avatar/{identity}/128',
                ],
                'crafatar' => [
                    'name' => 'Crafatar',
                    'website' => 'https://crafatar.com/',
                    'template' => 'https://crafatar.com/avatars/{identity}?size=128&overlay',
                ],
            ] as $id => $provider
        ) {
            $avatars->registerUrl(
                'minecraft',
                $id,
                [
                    'name' => $provider['name'],
                    'description' => 'minecraft::avatars.'.str_replace('-', '_', $id),
                    'website' => $provider['website'],
                ],
                $provider['template'],
                usernamePattern: $id === 'crafatar' ? null : '/^[A-Za-z0-9_]{3,16}$/D',
                default: $id === 'crafthead',
            );
        }
        config([
            'games.adapters.minecraft' => MinecraftAdapter::class,
            'games.auth_steps.minecraft' => [
                'games.minecraft.public.profile.edit',
                'games.minecraft.public.bedrock.show',
            ],
            'oauth.adapters.minecraft' => MinecraftOAuthAdapter::class,
        ]);
        config([
            'games.transports.minecraft.ping' => StatusTransport::class,
            'games.transports.minecraft.rcon' => RconTransport::class,
        ]);
    }

    public function boot(): void
    {
        $this->app['events']->listen(OAuthConfigurationValidating::class, ValidateOAuthConfiguration::class);
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'minecraft');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        RateLimiter::for('minecraft-bedrock', function (Request $request): Limit {
            $user = $request->session()->get('auth.identity_pending.id');

            return Limit::perMinute(10)->by($user === null ? 'ip:'.$request->ip() : 'user:'.$user);
        });
        app(SlotRegistry::class)->add(
            'minecraft',
            'account.sections',
            'link-profile',
            function (array $context, GameRegistry $games, OAuthRegistry $providers) {
                if ($games->activeId() !== 'minecraft' || $context['user']->isServiceAccount()) {
                    return '';
                }
                $provider = $providers->enabledForAdapter('minecraft')[0] ?? null;
                if ($provider === null || $games->identityFor($context['user'], 'minecraft')?->verified_at) {
                    return '';
                }

                return view('minecraft::default.profile.link', ['provider' => $provider]);
            },
        );
        if (app(GameRegistry::class)->activeId() === 'minecraft') {
            config([
                'oauth.providers.minecraft' => [
                    'name' => 'Minecraft Java (Microsoft)',
                    'sign_in_name' => 'Microsoft',
                    'sign_in_icon' => 'microsoft',
                    'game' => 'minecraft',
                    'adapter' => 'minecraft',
                    'icon' => 'controller',
                    'console' => 'https://entra.microsoft.com/',
                    'documentation' => 'https://socialiteproviders.com/Minecraft/',
                    'help' => 'minecraft::oauth',
                ],
            ]);
        }
    }
}
