<?php

namespace App\Kraft\Wallet;

use Illuminate\Validation\ValidationException;

class Money
{
    public const int MaxBalance = 9_999_999_999_999_999;

    public static function parse(string $amount): int
    {
        $amount = trim($amount);
        if (!preg_match('/^(\d{1,14})(?:[.,](\d{1,2}))?$/D', $amount, $parts)) {
            throw ValidationException::withMessages(['amount' => __('site.account.wallet.invalid_amount')]);
        }
        $minor = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($minor > self::MaxBalance) {
            throw ValidationException::withMessages(['amount' => __('site.account.wallet.invalid_amount')]);
        }

        return $minor;
    }

    public static function decimal(int $minor): string
    {
        $absolute = abs($minor);

        return ($minor < 0 ? '-' : '').
            intdiv($absolute, 100).
            '.'.
            str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $minor, ?string $locale = null): string
    {
        [$whole, $fraction] = explode('.', self::decimal($minor));
        $language = strtolower(explode('-', str_replace('_', '-', $locale ?? app()->getLocale()))[0]);
        $french = $language === 'fr';
        $decimalComma = in_array($language, ['fr', 'de', 'es', 'pt'], true);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', $french ? ' ' : ($decimalComma ? '.' : ','), $whole);

        return $grouped.($decimalComma ? ',' : '.').$fraction;
    }
}
