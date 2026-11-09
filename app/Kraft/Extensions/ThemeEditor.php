<?php

namespace App\Kraft\Extensions;

use RuntimeException;

final class ThemeEditor
{
    private const array MODES = ['light', 'dark'];

    private const array STYLES = ['ui', 'content'];

    public static function validate(array $manifest, string $directory): void
    {
        if (!array_key_exists('editor', $manifest)) {
            return;
        }
        $editor = $manifest['editor'];
        if (
            $manifest['type'] !== 'theme' ||
            !is_array($editor) ||
            array_diff(array_keys($editor), ['appearance', ...self::MODES]) ||
            (array_key_exists('appearance', $editor) && !in_array($editor['appearance'], ['auto', ...self::MODES], true))
        ) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }

        $public = realpath($directory.'/public');
        $root = realpath($directory);
        foreach (self::MODES as $mode) {
            if (!array_key_exists($mode, $editor)) {
                continue;
            }
            $styles = $editor[$mode];
            if (!is_array($styles) || array_diff(array_keys($styles), self::STYLES)) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            foreach ($styles as $asset) {
                if (
                    !is_string($asset) ||
                    !preg_match('~^(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.css$~D', $asset) ||
                    str_contains($asset, '..') ||
                    in_array($asset, $manifest['assets']['styles'] ?? [], true)
                ) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
                $path = realpath($directory.'/public/'.$asset);
                if (
                    !$root ||
                    !$public ||
                    !str_starts_with($public, $root.DIRECTORY_SEPARATOR) ||
                    !$path ||
                    !str_starts_with($path, $public.DIRECTORY_SEPARATOR) ||
                    !is_file($path)
                ) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
            }
        }
    }

    public static function assets(array $manifest): array
    {
        $editor = $manifest['editor'] ?? [];

        return array_merge(...array_map(fn (string $mode) => array_values($editor[$mode] ?? []), self::MODES));
    }

    public static function configuration(?array $theme): array
    {
        $editor = $theme['editor'] ?? [];
        $configuration = ['appearance' => $editor['appearance'] ?? 'auto'];
        foreach (self::MODES as $mode) {
            $styles = [];
            foreach ($editor[$mode] ?? [] as $kind => $asset) {
                $url = route('site.extensions.assets.show', ['id' => $theme['id'], 'asset' => $asset]);
                $styles[$kind] = parse_url($url, PHP_URL_PATH);
            }
            $configuration[$mode] = (object) $styles;
        }

        return $configuration;
    }
}
