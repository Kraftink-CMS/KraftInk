<?php

namespace App\Kraft\Auth;

use App\Models\Accounts\User;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\Contracts\ProvidesAccountNames;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountIdentity
{
    public static function expression(?Connection $connection = null): string
    {
        return ($connection ?? DB::connection())->getDriverName() === 'pgsql' ? 'username_key' : 'LOWER(username)';
    }

    public static function normalize(Request $request, ?User $user = null): void
    {
        $username = $request->input('username', $user?->username ?? '');
        $email = $request->input('email');
        if (is_string($email)) {
            $email = mb_strtolower(trim($email));
        }
        $request->merge([
            'email' => $email === '' ? null : $email,
            'username' => is_string($username) ? self::normalizeUsername(trim($username)) : $username,
        ]);
    }

    public static function usernameRules(?User $user = null, bool $unique = true): array
    {
        $pattern = self::presentation()['pattern'];
        $rules = [
            'bail',
            'required',
            'string',
            'min:2',
            'max:40',
            function (string $attribute, mixed $value, Closure $fail) use ($pattern, $user): void {
                if (is_string($value) && $user && $value === $user->username) {
                    return;
                }
                if (!is_string($value) || preg_match('~^'.$pattern.'$~D', $value) !== 1) {
                    $fail(__('validation.regex', ['attribute' => __('site.auth.account.username')]));
                }
            },
        ];
        if ($unique) {
            $rules[] = function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (
                    User::query()
                        ->whereRaw(self::expression().' = ?', [mb_strtolower((string) $value)])
                        ->when($user, fn ($query) => $query->whereKeyNot($user->id))
                        ->exists()
                ) {
                    $fail(__('validation.unique', ['attribute' => __('site.auth.account.username')]));
                }
            };
        }

        return $rules;
    }

    public static function presentation(?User $user = null): array
    {
        $default = ['pattern' => '[A-Za-z0-9][A-Za-z0-9_.\\-]{1,39}', 'help' => __('site.auth.account.username_help')];
        $adapter = self::usernameAdapter();
        $presentation = $adapter ? $adapter->accountNamePresentation() : $default;
        if ($user) {
            $presentation['pattern'] = '(?:'.$presentation['pattern'].'|'.self::literalPattern($user->username).')';
        }

        return $presentation;
    }

    public static function literalPattern(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '.' => '\\.',
            '+' => '\\+',
            '*' => '\\*',
            '?' => '\\?',
            '[' => '\\[',
            ']' => '\\]',
            '^' => '\\^',
            '$' => '\\$',
            '(' => '\\(',
            ')' => '\\)',
            '{' => '\\{',
            '}' => '\\}',
            '|' => '\\|',
        ]);
    }

    private static function usernameAdapter(): ?ProvidesAccountNames
    {
        /** @var GameRegistry $registry */
        $registry = app(GameRegistry::class);
        $adapter = $registry->find($registry->activeId());

        return $adapter instanceof ProvidesAccountNames ? $adapter : null;
    }

    public static function normalizeUsername(string $username): string
    {
        return self::usernameAdapter()?->normalizeAccountName($username) ?? $username;
    }

    public static function availableUsername(string $preferred, ?Connection $connection = null): string
    {
        $connection ??= DB::connection();
        $base = ltrim(preg_replace('/[^a-zA-Z0-9_.-]+/', '-', Str::ascii(trim($preferred))) ?? '', '._-');
        $base = substr($base, 0, 40);
        $base = strlen($base) >= 2 ? $base : 'member'.($base !== '' ? '-'.$base : '');
        $candidate = $base;
        while (
            $connection
                ->table('users')
                ->whereRaw(self::expression($connection).' = ?', [strtolower($candidate)])
                ->exists()
        ) {
            $ending = '-'.random_int(10_000_000, 99_999_999);
            $candidate = substr($base, 0, 40 - strlen($ending)).$ending;
        }

        return $candidate;
    }
}
