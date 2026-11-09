<?php

namespace App\Kraft;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Extensions\ExtensionManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class Appearance
{
    public const string COOKIE = 'kraft_appearance';

    public static function validModes(mixed $modes): bool
    {
        return is_array($modes) &&
            array_is_list($modes) &&
            count($modes) >= 1 &&
            count($modes) <= 3 &&
            array_filter($modes, 'is_string') === $modes &&
            count(array_unique($modes, SORT_REGULAR)) === count($modes) &&
            !array_diff($modes, ['light', 'dark', 'auto']) &&
            (in_array('light', $modes, true) || in_array('dark', $modes, true)) &&
            (!in_array('auto', $modes, true) || (in_array('light', $modes, true) && in_array('dark', $modes, true)));
    }

    public function modes(?array $theme): array
    {
        if ($theme === null) {
            return ['light', 'dark', 'auto'];
        }
        $modes = $theme['capabilities']['appearance'] ?? null;

        if (!self::validModes($modes)) {
            return [($theme['color_scheme'] ?? null) === 'dark' ? 'dark' : 'light'];
        }

        return in_array('light', $modes, true) && in_array('dark', $modes, true) ? ['light', 'dark', 'auto'] : $modes;
    }

    public function policy(?array $theme): array
    {
        $modes = $this->modes($theme);
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $configured = $settings->get('site.appearance', 'user');
        $choice = in_array($configured, ['user', 'light', 'dark'], true) ? $configured : 'user';
        $concrete = array_values(array_intersect($modes, ['light', 'dark']));
        $selectable = $choice === 'user' && count($concrete) > 1;

        return [
            'choice' => $choice,
            'modes' => $modes,
            'selectable' => $selectable,
            'forced' => $selectable ? null : (in_array($choice, $concrete, true) ? $choice : $concrete[0]),
        ];
    }

    public function resolve(Request $request, ?array $theme): array
    {
        $policy = $this->policy($theme);
        $modes = $policy['modes'];
        $preference = $request->user()?->appearance ?? $request->cookie(self::COOKIE);
        $choice =
            $policy['forced'] ?? (is_string($preference) && in_array($preference, $modes, true) ? $preference : 'auto');
        $effective =
            $choice === 'auto'
                ? (trim((string) $request->header('Sec-CH-Prefers-Color-Scheme'), '"') === 'dark'
                    ? 'dark'
                    : 'light')
                : $choice;

        return [
            'choice' => $choice,
            'effective' => $effective,
            'modes' => $modes,
            'selectable' => $policy['selectable'],
        ];
    }

    public function select(Request $request, string $mode): Cookie
    {
        $policy = $this->policy(app(ExtensionManager::class)->activeTheme());
        if (!$policy['selectable'] || !in_array($mode, $policy['modes'], true)) {
            throw new HttpException(422);
        }
        if ($request->user() && $request->user()->appearance !== $mode) {
            $request
                ->user()
                ->forceFill(['appearance' => $mode])
                ->save();
        }

        return cookie()->make(
            self::COOKIE,
            $mode,
            525600,
            '/',
            config('session.domain'),
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
