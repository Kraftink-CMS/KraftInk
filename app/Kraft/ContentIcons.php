<?php

namespace App\Kraft;

use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\ThemeContext;

class ContentIcons
{
    public const NAME_PATTERN = '[a-z0-9][a-z0-9\-]*';

    public const MAX_NAME_LENGTH = 60;

    private ?array $library = null;

    private ?array $bootstrap = null;

    public static function validName(string $name): bool
    {
        return strlen($name) <= self::MAX_NAME_LENGTH && preg_match('/^'.self::NAME_PATTERN.'$/D', $name) === 1;
    }

    public function resolve(?string $name): ?array
    {
        if ($name === null || !self::validName($name)) {
            return null;
        }

        $library = $this->library();
        if (isset($library['entries'][$name])) {
            return ['url' => $this->assetUrl($library['theme'], $library['entries'][$name]['asset'])];
        }

        $bootstrap = $this->bootstrap();

        return $bootstrap === [] || isset($bootstrap[$name])
            ? ['class' => 'bi bi-'.$name, 'fallback' => $library['theme'] !== null]
            : null;
    }

    public function catalog(): array
    {
        $library = $this->library();
        $entries = [];
        foreach ($library['entries'] as $key => $icon) {
            $entries[$key] = [
                'name' => $icon['name'],
                'url' => $this->assetUrl($library['theme'], $icon['asset']),
            ];
        }

        return [
            'name' => $library['name'],
            'theme' => $library['theme'],
            'entries' => (object) $entries,
            'bootstrap' => array_map('strval', array_keys($this->bootstrap())),
        ];
    }

    public function libraryName(): string
    {
        return $this->library()['name'];
    }

    private function library(): array
    {
        if ($this->library === null) {
            $theme = app(ThemeContext::class)->current();
            if (($theme['target'] ?? null) !== 'site') {
                $theme = app(ExtensionManager::class)->activeTheme('site');
            }

            $this->library = [
                'name' => $theme['icons']['name'] ?? 'Bootstrap Icons',
                'theme' => isset($theme['icons']) ? $theme['id'] : null,
                'entries' => $theme['icons']['entries'] ?? [],
            ];
        }

        return $this->library;
    }

    private function bootstrap(): array
    {
        if ($this->bootstrap === null) {
            $built = public_path('build/bootstrap-icons.json');
            $source = base_path('node_modules/bootstrap-icons/font/bootstrap-icons.json');
            $path = is_file($built) ? $built : $source;
            $this->bootstrap = is_file($path) ? (array) json_decode(file_get_contents($path), true) : [];
        }

        return $this->bootstrap;
    }

    private function assetUrl(string $theme, string $asset): string
    {
        return route('site.extensions.assets.show', ['id' => $theme, 'asset' => $asset], absolute: false);
    }
}
