<?php

namespace App\Kraft\Updates;

use RuntimeException;

class ReleaseVersion
{
    public static function channel(mixed $version): ?string
    {
        if (!is_string($version) || strlen($version) > 40) {
            return null;
        }
        if (
            !preg_match(
                '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(alpha|beta)\.[1-9]\d*)?$/D',
                $version,
                $matches,
            )
        ) {
            return null;
        }

        return $matches[1] ?? 'stable';
    }

    public static function manifestChannel(array $manifest): string
    {
        $channel = self::channel($manifest['version'] ?? null);
        if (
            $channel === null ||
            ($manifest['channel'] ?? null) !== $channel
        ) {
            throw new RuntimeException('Invalid CMS release version or channel.');
        }

        return $channel;
    }
}
