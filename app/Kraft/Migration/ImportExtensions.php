<?php

namespace App\Kraft\Migration;

use App\Kraft\Extensions\ExtensionManager;
use Illuminate\Database\Connection;

final class ImportExtensions
{
    public function equivalent(string $name, string $type, string $source): ?string
    {
        $name = $this->normalize($name);
        $matches = [];
        foreach (app(ExtensionManager::class)->all() as $extension) {
            if (
                !$extension['valid'] ||
                $extension['type'] !== $type ||
                ($type === 'theme' && $extension['target'] !== 'site')
            ) {
                continue;
            }
            $aliases = [$extension['id'], $extension['name']];
            foreach ($extension['imports'][$source] ?? [] as $prefix) {
                if (is_string($prefix)) {
                    $aliases[] = preg_replace('/_+$/', '', $prefix);
                }
            }
            if (in_array($name, array_map($this->normalize(...), $aliases), true)) {
                $matches[] = $extension['id'];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function inspect(string $source, Connection $connection, array $tables): array
    {
        $plugins = [];
        $unavailable = [];
        $theme = null;
        foreach (app(ExtensionManager::class)->all() as $extension) {
            if ($extension['type'] !== 'plugin') {
                continue;
            }
            foreach ($extension['imports'][$source] ?? [] as $prefix) {
                foreach (array_keys($tables) as $table) {
                    if (is_string($prefix) && str_starts_with($table, $prefix)) {
                        $plugins[] = $extension['id'];
                    }
                }
            }
        }
        /** @var SourceAdapters $sourceAdapters */
        $sourceAdapters = app(SourceAdapters::class);
        $names = $sourceAdapters->get($source)->extensionNames($connection, $tables);
        foreach ($names['plugin'] as $name) {
            $id = $this->equivalent($name, 'plugin', $source);
            if ($id !== null) {
                $plugins[] = $id;
            } else {
                $unavailable[] = ['type' => 'plugin', 'name' => mb_substr($name, 0, 120)];
            }
        }
        $sourceTheme = $names['theme'][0] ?? null;
        if (is_string($sourceTheme) && $sourceTheme !== '') {
            $theme = $this->equivalent($sourceTheme, 'theme', $source);
            if ($theme === null) {
                $unavailable[] = ['type' => 'theme', 'name' => mb_substr($sourceTheme, 0, 120)];
            }
        }

        return [
            'plugins' => array_values(array_unique($plugins)),
            'theme' => $theme,
            'source_theme' => $sourceTheme,
            'unavailable' => $unavailable,
        ];
    }

    private function normalize(string $name): string
    {
        return strtolower(trim($name));
    }
}
