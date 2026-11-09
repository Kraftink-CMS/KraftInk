<?php

namespace App\Kraft\Updates;

use KraftReleaseBuild;

class ReleaseBuild
{
    public static function manifest(array $manifest): ?array
    {
        self::load();

        return KraftReleaseBuild::manifest($manifest);
    }

    public static function allows(array $manifest): bool
    {
        self::load();

        return KraftReleaseBuild::allows($manifest, config('manifest'));
    }

    public static function validate(array $manifest): void
    {
        self::load();
        KraftReleaseBuild::validate($manifest, config('manifest'));
    }

    public static function verifyConfiguration(array $manifest, string $source): void
    {
        self::load();
        KraftReleaseBuild::verifyConfiguration($manifest, $source);
    }

    private static function load(): void
    {
        if (!class_exists(KraftReleaseBuild::class, false)) {
            require_once base_path('updater/releases.php');
        }
    }
}
