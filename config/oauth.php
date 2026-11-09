<?php

use App\Kraft\OAuth\Adapters\DiscordAdapter;
use App\Kraft\OAuth\Adapters\FacebookAdapter;
use App\Kraft\OAuth\Adapters\GoogleAdapter;
use App\Kraft\OAuth\Adapters\MicrosoftAdapter;
use App\Kraft\OAuth\Adapters\TwitchAdapter;
use App\Kraft\OAuth\Adapters\XAdapter;

return [
    'adapters' => [
        'google' => GoogleAdapter::class,
        'discord' => DiscordAdapter::class,
        'microsoft' => MicrosoftAdapter::class,
        'facebook' => FacebookAdapter::class,
        'twitch' => TwitchAdapter::class,
        'twitter' => XAdapter::class,
    ],
    'providers' => [
        'google' => [
            'name' => 'Google',
            'adapter' => 'google',
            'icon' => 'google',
            'console' => 'https://console.cloud.google.com/apis/credentials',
            'documentation' => 'https://developers.google.com/identity/openid-connect/openid-connect',
            'help' => 'admin.integrations.oauth.google',
        ],
        'discord' => [
            'name' => 'Discord',
            'adapter' => 'discord',
            'icon' => 'discord',
            'console' => 'https://discord.com/developers/applications',
            'documentation' => 'https://docs.discord.com/developers/topics/oauth2',
            'help' => 'admin.integrations.oauth.discord',
        ],
        'microsoft' => [
            'name' => 'Microsoft',
            'adapter' => 'microsoft',
            'icon' => 'microsoft',
            'console' => 'https://entra.microsoft.com/',
            'documentation' => 'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app',
            'help' => 'admin.integrations.oauth.microsoft',
            'fields' => [
                'tenant' => [
                    'label' => 'admin.integrations.oauth.tenant',
                    'help' => 'admin.integrations.oauth.tenant_help',
                    'type' => 'text',
                    'default' => 'common',
                    'rules' => [
                        'required',
                        'string',
                        'max:255',
                        'regex:/^(?:common|consumers|organizations|[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}|[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?)+)$/D',
                    ],
                ],
            ],
        ],
        'facebook' => [
            'name' => 'Facebook',
            'adapter' => 'facebook',
            'icon' => 'facebook',
            'console' => 'https://developers.facebook.com/apps/',
            'documentation' => 'https://developers.facebook.com/docs/facebook-login/',
            'help' => 'admin.integrations.oauth.facebook',
        ],
        'twitch' => [
            'name' => 'Twitch',
            'adapter' => 'twitch',
            'icon' => 'twitch',
            'console' => 'https://dev.twitch.tv/console/apps',
            'documentation' => 'https://dev.twitch.tv/docs/authentication/register-app/',
            'help' => 'admin.integrations.oauth.twitch',
        ],
        'twitter' => [
            'name' => 'X (Twitter)',
            'adapter' => 'twitter',
            'icon' => 'twitter-x',
            'console' => 'https://developer.x.com/en/portal/dashboard',
            'documentation' => 'https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code',
            'help' => 'admin.integrations.oauth.twitter',
        ],
    ],
];
