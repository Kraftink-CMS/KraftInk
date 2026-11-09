<?php

return [
    'title' => 'Data retention',
    'description' => 'Choose how long to keep older versions of your content.',
    'saved' => 'Cleanup settings have been saved.',
    'command_description' => 'Clean up old versions and temporary data according to the configured retention policy',
    'schedule_help' => 'These periods apply at the next cleanup. With automatic tasks enabled, maintenance runs every hour. You can also run cleanup manually.',
    'history_title' => 'Content history',
    'other_cleanups' => 'Other cleanup',
    'fields' => [
        'history' => [
            'label' => 'History duration, in days',
            'help' => 'Older versions are deleted automatically.',
        ],
        'temporary' => [
            'label' => 'Unused files, in days',
            'help' => 'Images removed from your content and its history, expired guest access.',
        ],
        'technical' => [
            'label' => 'Old deliveries and tasks, in days',
            'help' => 'Old notification deliveries and completed tasks. Tasks that need another attempt are preserved.',
        ],
    ],
    'history_limit' => 'Versions to keep per content item',
    'history_limit_help' => 'When this limit is exceeded, the oldest versions are deleted.',
    'preserved_help' => 'Your drafts, current content and images still in use are preserved.',
    'audit_link' => 'Configure activity log retention',
];
