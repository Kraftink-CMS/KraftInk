<?php

declare(strict_types=1);

class KraftReleaseBuild
{
    public static function manifest(array $manifest): ?array
    {
        if (!array_key_exists('build', $manifest)) {
            return null;
        }
        $build = $manifest['build'];
        $version = $manifest['version'] ?? null;
        $core = '(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)';
        if (
            !is_array($build) ||
            count($build) !== 2 ||
            !is_string($build['version'] ?? null) ||
            strlen($build['version']) > 40 ||
            !preg_match('/^('.$core.')-alpha\.[1-9][0-9]*$/D', $build['version'], $source) ||
            !is_string($build['sha'] ?? null) ||
            !preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $build['sha']) ||
            !is_string($version) ||
            !preg_match('/^('.$core.')(?:-(alpha|beta)\.[1-9][0-9]*)?$/D', $version, $release) ||
            ($source[1] !== $release[1] && (isset($release[2]) || version_compare($release[1], $source[1], '<'))) ||
            (($release[2] ?? null) === 'alpha' && $build['version'] !== $version)
        ) {
            throw new RuntimeException('Invalid original alpha build provenance.');
        }

        return $build;
    }

    public static function installed(array $configuration): ?array
    {
        if (!array_key_exists('release', $configuration) || $configuration['release'] === null) {
            return null;
        }

        return self::manifest([
            'version' => $configuration['version'] ?? null,
            'build' => $configuration['release'],
        ]);
    }

    public static function allows(array $manifest, array $configuration): bool
    {
        $candidate = self::manifest($manifest);
        $installed = self::installed($configuration);
        if ($installed === null) {
            return true;
        }
        if ($candidate === null) {
            return false;
        }
        $comparison = version_compare($candidate['version'], $installed['version']);

        return $comparison > 0 || ($comparison === 0 && hash_equals($installed['sha'], $candidate['sha']));
    }

    public static function validate(array $manifest, array $configuration): void
    {
        if (!self::allows($manifest, $configuration)) {
            throw new RuntimeException('The release build would downgrade or replace the installed source snapshot.');
        }
    }

    public static function verifyConfiguration(array $manifest, string $source): void
    {
        $build = self::manifest($manifest);
        $configuration = self::configuration($source);
        if (
            ($configuration['version'] ?? null) !== ($manifest['version'] ?? null) ||
            self::installed($configuration) !== $build
        ) {
            throw new RuntimeException('The packaged CMS manifest does not match its signed release provenance.');
        }
    }

    public static function configuration(string $source): array
    {
        if (!class_exists(KraftManifest::class, false)) {
            require_once __DIR__.'/manifest.php';
        }

        return KraftManifest::decode($source, 'cms', 'kraftink');
    }
}
