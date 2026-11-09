<?php

namespace App\Kraft\Updates;

use App\Kraft\Settings;
use RuntimeException;

class UpdateChannel
{
    public function current(): string
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $channel = $settings->get('updates.channel', 'stable');

        return in_array($channel, ['stable', 'beta', 'alpha'], true) ? $channel : 'stable';
    }

    public function allows(string $channel): bool
    {
        if ($channel === 'stable') {
            return true;
        }

        return match ($this->current()) {
            'alpha' => in_array($channel, ['stable', 'beta', 'alpha'], true),
            'beta' => in_array($channel, ['stable', 'beta'], true),
            default => $channel === 'stable',
        };
    }

    public function validate(array $manifest): string
    {
        $channel = ReleaseVersion::manifestChannel($manifest);
        if ($channel !== 'stable' && !$this->allows($channel)) {
            throw new RuntimeException('This CMS release channel must be explicitly enabled.');
        }

        return $channel;
    }
}
