<?php

return [
    'create' => 'Create API access',
    'create_help' => 'Choose the data this application can access. API access never grants more permissions than your account.',
    'unavailable' => 'Creating access requires an enabled API and an account with local sign-in allowed. You can still revoke existing access.',
    'name' => 'Application name',
    'lifetime' => 'Lifetime in minutes',
    'lifetime_help' => 'The site setting limits the lifetime, up to 30 days.',
    'rights' => 'Allowed data and actions',
    'rights_help' => 'Account permissions and extension availability are checked on every request.',
    'password' => 'Current password',
    'code' => 'Two-factor authentication code',
    'created' => 'Your API access is ready',
    'copy_once' => 'Copy this secret now and store it in the intended application. It will not be displayed again. Anyone holding it can use the selected permissions.',
    'secret' => 'Bearer token',
    'back' => 'Back to my API access',
    'scopes' => [
        'site_news' => ['label' => 'Read public news', 'description' => 'Read published news and its public content. Drafts and restricted content remain excluded.'],
        'site_servers' => ['label' => 'Read public server status', 'description' => 'Read availability and player counts for public servers. No access to internal addresses, secrets or commands.'],
        'profile' => ['label' => 'Read my profile', 'description' => 'Your account ID, username, roles and creation date.'],
        'games' => ['label' => 'Read my game identities', 'description' => 'IDs, usernames and verification state of your game profiles.'],
        'email' => ['label' => 'Read my email address', 'description' => 'The email address associated with your account.'],
    ],
];
