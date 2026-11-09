<?php

namespace App\Kraft\Extensions;

use App\Kraft\Games\GameRegistry;

class GameSupport
{
    private const array FIELDS = ['supported', 'excluded', 'enhancements'];

    public static function declarations(array $package): array
    {
        return array_intersect_key($package, ['games' => true]);
    }

    public static function valid(array $package, ?string $type = null): bool
    {
        if (array_intersect(['supported_games', 'excluded_games', 'game_enhancements'], array_keys($package))) {
            return false;
        }
        if (($type ?? $package['type'] ?? null) !== 'plugin') {
            return !array_key_exists('games', $package);
        }
        $games = $package['games'] ?? null;
        if (!is_array($games) || array_diff(array_keys($games), self::FIELDS)) {
            return false;
        }
        $supported = $games['supported'] ?? null;
        $all = $supported === ['*'];
        if (!$all && (!$supported || !self::validList($supported))) {
            return false;
        }
        foreach (['excluded', 'enhancements'] as $field) {
            if (array_key_exists($field, $games) && !self::validList($games[$field])) {
                return false;
            }
        }
        $excluded = $games['excluded'] ?? [];
        $enhanced = $games['enhancements'] ?? [];

        return ($all || (!$excluded && !array_diff($enhanced, $supported))) &&
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

    public static function matches(array $package, ?string $game, ?string $type = null): bool
    {
        if (!self::valid($package, $type)) {
            return false;
        }
        if (!isset($package['games'])) {
            return true;
        }
        $supported = $package['games']['supported'];

        return ($supported === ['*'] || in_array($game, $supported, true)) &&
            !in_array($game, $package['games']['excluded'] ?? [], true);
    }

    public static function same(array $first, array $second, string $type): bool
    {
        if (!self::valid($first, $type) || !self::valid($second, $type)) {
            return false;
        }
        foreach (self::FIELDS as $field) {
            $left = $first['games'][$field] ?? [];
            $right = $second['games'][$field] ?? [];
            if (count($left) !== count($right) || array_diff($left, $right)) {
                return false;
            }
        }

        return true;
    }

    public static function summary(array $package): string
    {
        if (!self::valid($package, 'plugin')) {
            return '—';
        }
        $supported = $package['games']['supported'];
        $all = $supported === ['*'];
        $excluded = $package['games']['excluded'] ?? [];
        if ($all && !$excluded) {
            return __('system.extensions.all_games');
        }
        $games = app(GameRegistry::class);
        $names = implode(', ', array_map(fn ($id) => $games->find($id)?->metadata()['name'] ?? $id, $all ? $excluded : $supported));

        return $all ? __('system.extensions.all_games_except', ['games' => $names]) : $names;
    }
}
