<?php

namespace App\Kraft;

class TextTranslations
{
    public function __construct(private LocalePolicy $policy)
    {
    }

    public function rules(array $fields, string $prefix = 'translations'): array
    {
        if (!$this->policy->allowsChoice()) {
            return [$prefix => ['prohibited']];
        }
        $rules = [
            $prefix => ['nullable', 'array:'.implode(',', array_keys(config('kraft.locales')))],
            $prefix.'.*' => ['array:'.implode(',', array_keys($fields))],
        ];
        foreach ($fields as $field => $options) {
            $rules[$prefix.'.*.'.$field] = ['nullable', 'string', 'max:'.($options['max'] ?? 255)];
        }

        return $rules;
    }

    public function normalize(array $submitted, array $existing, array $fields): array
    {
        if (!$this->policy->allowsChoice()) {
            return $existing;
        }
        foreach ($submitted as $locale => $values) {
            if (!$this->policy->valid($locale) || $locale === $this->policy->defaultLocale()) {
                continue;
            }
            foreach ($fields as $field => $options) {
                if (!array_key_exists($field, $values)) {
                    continue;
                }
                $value =
                    ($options['type'] ?? 'text') === 'richtext'
                        ? Html::clean($values[$field] ?? '')
                        : trim($values[$field] ?? '');
                if ($this->filled($value, ($options['type'] ?? 'text') === 'richtext')) {
                    $existing[$locale][$field] = $value;
                } else {
                    unset($existing[$locale][$field]);
                }
            }
            if (empty($existing[$locale])) {
                unset($existing[$locale]);
            }
        }

        return $existing;
    }

    public function resolve(
        string $base,
        array $translations,
        string $field,
        ?string $originLocale = null,
        ?string $locale = null,
        bool $html = false,
    ): string {
        if (!$this->policy->allowsChoice()) {
            return $base;
        }
        $originLocale ??= $this->policy->defaultLocale();
        foreach (array_unique([$locale ?? app()->getLocale(), 'en', $this->policy->defaultLocale()]) as $language) {
            if ($language === $originLocale && $this->filled($base, $html)) {
                return $base;
            }
            $value = $translations[$language][$field] ?? '';
            if (is_string($value) && $this->filled($value, $html)) {
                return $value;
            }
        }

        return $base;
    }

    public function filled(?string $value, bool $html = false): bool
    {
        $value ??= '';
        $content = $html ? strip_tags($value, ['img', 'hr']) : $value;

        return preg_match('/[^\s\p{Z}]/u', html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1;
    }
}
