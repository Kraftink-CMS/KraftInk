<?php

namespace App\Kraft\Games;

final class PlayerUuid
{
    public static function compact(string $uuid): ?string
    {
        return preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})$/iD', $uuid)
            ? strtolower(str_replace('-', '', $uuid))
            : null;
    }

    public static function hyphenated(string $uuid): ?string
    {
        $compact = self::compact($uuid);
        if ($compact === null) {
            return null;
        }

        return substr($compact, 0, 8).
            '-'.
            substr($compact, 8, 4).
            '-'.
            substr($compact, 12, 4).
            '-'.
            substr($compact, 16, 4).
            '-'.
            substr($compact, 20);
    }
}
