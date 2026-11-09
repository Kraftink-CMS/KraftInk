<?php

return [
    'locales' => [
        'fr' => 'Français',
        'en' => 'English',
        'es' => 'Español',
        'de' => 'Deutsch',
        'pt' => 'Português',
    ],

    'theme' => null,
    'admin_theme' => null,

    'safe_mode' => (bool) env('KRAFT_SAFE_MODE', false),
    'php_binary' => env('KRAFT_PHP_BINARY'),

    'extensions' => [
        'installed' => [
            'plugins' => dirname(__DIR__).'/extensions/installed/plugins',
            'themes' => dirname(__DIR__).'/extensions/installed/themes',
            'games' => dirname(__DIR__).'/extensions/installed/games',
        ],
        'bundled' => [
            'plugins' => dirname(__DIR__).'/extensions/bundled/plugins',
            'themes' => dirname(__DIR__).'/extensions/bundled/themes',
            'games' => dirname(__DIR__).'/extensions/bundled/games',
        ],
    ],

    'update_tools' => [
        'mysql' => [
            'dump' => env('KRAFT_DB_DUMP'),
            'restore' => env('KRAFT_DB_RESTORE'),
        ],
        'pgsql' => [
            'dump' => env('KRAFT_DB_DUMP'),
            'restore' => env('KRAFT_DB_RESTORE'),
        ],
    ],

    'games' => [],
];
