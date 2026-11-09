<?php

namespace App\Kraft\Extensions;

class BuiltinExtensions
{
    public function inventory(): array
    {
        return [
            'game' => config('manifest.bundled.game', []),
            'plugin' => config('manifest.bundled.plugin', []),
            'theme' => config('manifest.bundled.theme', []),
        ];
    }

    public function contains(string $id, ?string $type = null): bool
    {
        return $type === null ? $this->type($id) !== null : in_array($id, $this->inventory()[$type] ?? [], true);
    }

    public function type(string $id): ?string
    {
        return array_find_key($this->inventory(), fn ($ids) => in_array($id, $ids, true));
    }

    public function path(string $id): ?string
    {
        $directory = match ($this->type($id)) {
            'game' => 'games',
            'plugin' => 'plugins',
            'theme' => 'themes',
            default => null,
        };

        return $directory
            ? rtrim(config('kraft.extensions.bundled.'.$directory), '/\\').'/'.$id
            : null;
    }
}
