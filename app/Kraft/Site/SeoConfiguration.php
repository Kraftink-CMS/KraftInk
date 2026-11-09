<?php

namespace App\Kraft\Site;

use App\Kraft\TextTranslations;
use Closure;
use Illuminate\Validation\Rule;

class SeoConfiguration
{
    public const string DEFAULT_TITLE = '{title} - {website_name}';

    public const string DEFAULT_DESCRIPTION = '{description}';

    public const array VARIABLES = [
        'title',
        'description',
        'website_name',
        'site_description',
        'url',
        'path',
        'locale',
        'year',
        'page',
    ];

    public const array ROBOTS = ['index,follow', 'noindex,follow', 'noindex,nofollow'];

    public static function fields(bool $page = false): array
    {
        $fields = [
            'title' => ['max' => 300],
            'description' => ['max' => 1000, 'type' => 'textarea'],
            'keywords' => ['max' => 500],
        ];
        if ($page) {
            $fields = [
                'title' => $fields['title'],
                'title_template' => ['max' => 300],
                'description' => $fields['description'],
                'keywords' => $fields['keywords'],
            ];
        }

        return $fields;
    }

    public static function templateRule(array $variables = []): Closure
    {
        $tokens = array_map(fn ($variable) => '{'.$variable.'}', [...self::VARIABLES, ...$variables]);

        return static function (string $attribute, mixed $value, Closure $fail) use ($tokens): void {
            if (!is_string($value)) {
                return;
            }
            if (strpbrk(str_replace($tokens, '', $value), '{}') !== false) {
                $fail(__('seo.invalid_variable', ['variables' => implode(', ', $tokens)]));
            }
        };
    }

    public static function rules(bool $page = false, array $variables = []): array
    {
        $fields = self::fields($page);
        $template = self::templateRule($variables);
        $rules = array_map(fn ($options) => ['nullable', 'string', 'max:'.$options['max'], $template], $fields);
        $rules['robots'] = ['nullable', Rule::in($page ? ['', ...self::ROBOTS] : self::ROBOTS)];
        $rules = [...$rules, ...app(TextTranslations::class)->rules($fields)];
        foreach ($fields as $field => $options) {
            if (isset($rules['translations.*.'.$field])) {
                $rules['translations.*.'.$field][] = $template;
            }
        }
        if ($page) {
            $rules['target'] = ['required', 'string', 'max:300'];
            $rules['path'] = ['prohibited'];
        }

        return $rules;
    }

    public static function normalizePath(string $value): ?string
    {
        $value = trim($value);
        if (
            !str_starts_with($value, '/') ||
            str_starts_with($value, '//') ||
            preg_match('/[\\\\\x00-\x20\x7f?#]/', $value) ||
            preg_match('/%(?![a-f0-9]{2})/i', $value)
        ) {
            return null;
        }
        $wildcard = str_ends_with($value, '/*');
        $path = $wildcard ? substr($value, 0, -2) : rtrim($value, '/');
        if (str_contains($path, '*')) {
            return null;
        }
        $segments = [];
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '' && $path === '') {
                continue;
            }
            if (
                $decoded === '' ||
                in_array($decoded, ['.', '..'], true) ||
                preg_match('/[\\\\\x00-\x20\x7f\/?#*%]/', $decoded)
            ) {
                return null;
            }
            $segments[] = rawurlencode($decoded);
        }

        return '/'.implode('/', $segments).($wildcard ? ($segments ? '/*' : '*') : '');
    }
}
