<?php

namespace App\Kraft\Extensions;

use App\Kraft\Games\GameRegistry;

class GameSupport
{
    private const array FIELDS = ['supported_games', 'excluded_games', 'game_enhancements'];

    public static function declarations(array $package): array
    {
        return array_intersect_key($package, array_flip(self::FIELDS));
    }

    public static function valid(array $package, ?string $type = null): bool
    {
        foreach (self::declarations($package) as $games) {
            if (($type ?? $package['type'] ?? null) !== 'plugin' || !self::validList($games)) {
                return false;
            }
        }

        $supported = $package['supported_games'] ?? [];
        $excluded = $package['excluded_games'] ?? [];
        $enhanced = $package['game_enhancements'] ?? [];

        return !($supported && $excluded) && (!$supported || !array_diff($enhanced, $supported)) &&
            !array_intersect($enhanced, $excluded);
    }

    private static function validList(mixed $games): bool
    {
        if (!is_array($games) || !array_is_list($games) || count($games) > 50) {
            return false;
        }

        $seen = [];
        foreach ($games as $game) {
            if (!is_string($game) || !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $game) || isset($seen[$game])) {
                return false;
            }
            $seen[$game] = true;
        }

        return true;
    }

    public static function matches(array $package, ?string $game): bool
    {
        if (!self::valid($package, $package['type'] ?? 'plugin')) {
            return false;
        }
        $supported = $package['supported_games'] ?? [];

        return (!$supported || in_array($game, $supported, true)) &&
            !in_array($game, $package['excluded_games'] ?? [], true);
    }

    public static function same(array $first, array $second, string $type): bool
    {
        if (!self::valid($first, $type) || !self::valid($second, $type)) {
            return false;
        }
        foreach (self::FIELDS as $field) {
            $left = $first[$field] ?? [];
            $right = $second[$field] ?? [];
            if (count($left) !== count($right) || array_diff($left, $right)) {
                return false;
            }
        }

        return true;
    }

    public static function summary(array $package): string
    {
        if (!self::valid($package, $package['type'] ?? 'plugin')) {
            return '—';
        }
        $supported = $package['supported_games'] ?? [];
        $excluded = $package['excluded_games'] ?? [];
        if (!$supported && !$excluded) {
            return __('system.extensions.all_games');
        }
        $games = app(GameRegistry::class);
        $names = implode(', ', array_map(fn ($id) => $games->find($id)?->metadata()['name'] ?? $id, $supported ?: $excluded));

        return $supported ? $names : __('system.extensions.all_games_except', ['games' => $names]);
    }
}
