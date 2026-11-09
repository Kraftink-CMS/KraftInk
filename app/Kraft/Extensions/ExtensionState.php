<?php

namespace App\Kraft\Extensions;

enum ExtensionState: string
{
    case Present = 'present';
    case Installed = 'installed';
    case Active = 'active';
    case Suspended = 'quarantined';

    public static function listingValues(?string $type = null): array
    {
        $states = [self::Active, self::Installed, self::Present, self::Suspended];

        return array_values(array_map(
            fn (self $state) => $state->value,
            array_filter($states, fn (self $state) => $type !== 'game' || $state !== self::Installed),
        ));
    }
}
