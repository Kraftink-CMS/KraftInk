<?php

namespace App\Kraft\Extensions;

use App\Kraft\Html;
use App\Kraft\Navigation;
use App\Kraft\NavigationTargets;
use App\Kraft\Settings;
use App\Models\Media\MediaAsset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class ThemeConfiguration
{
    private const array TYPES = [
        'text',
        'textarea',
        'richtext',
        'url',
        'color',
        'number',
        'boolean',
        'select',
        'multiselect',
        'media',
        'menu',
        'repeater',
    ];

    private array $resolved = [];

    public function __construct(private readonly Settings $settings)
    {
    }

    public static function validateSchema(array $schema): void
    {
        if (!array_is_list($schema)) {
            self::invalid();
        }
        $keys = [];
        $sections = [];
        foreach ($schema as $section) {
            if (
                !is_array($section) ||
                !self::identifier($section['id'] ?? null) ||
                !self::label($section['label'] ?? null) ||
                (isset($section['description']) && !self::label($section['description'])) ||
                !is_array($section['fields'] ?? null) ||
                !$section['fields'] ||
                in_array($section['id'] ?? null, $sections, true)
            ) {
                self::invalid();
            }
            $sections[] = $section['id'];
            foreach ($section['fields'] as $key => $field) {
                if (in_array($key, $keys, true)) {
                    self::invalid();
                }
                self::validateField($key, $field);
                $keys[] = $key;
            }
        }
    }

    public function sections(array $theme): array
    {
        return $theme['configuration']['schema'] ?? [];
    }

    public function values(array $theme): array
    {
        if ($draft = app(ThemePreview::class)->draft($theme)) {
            return $draft['values'];
        }
        if (array_key_exists($theme['id'], $this->resolved)) {
            return $this->resolved[$theme['id']];
        }
        $saved = $this->settings->get($this->key($theme), []);
        $saved = is_array($saved) ? $saved : [];
        $values = [];
        foreach ($this->fields($theme) as $key => $field) {
            $default = $field['default'] ?? $this->empty($field);
            $candidate = array_key_exists($key, $saved) ? $saved[$key] : $default;
            $validator = Validator::make(['value' => $candidate], $this->rules(['value' => $field], theme: $theme));
            $values[$key] = $this->normalize($field, $validator->passes() ? $candidate : $default);
        }

        return $this->resolved[$theme['id']] = $values;
    }

    public function validate(array $theme, array $input): array
    {
        $fields = $this->fields($theme);
        $input = $this->prepare($fields, $input);
        $attributes = [];
        foreach ($fields as $key => $field) {
            $attributes['values.'.$key] = __($field['label']);
            foreach ($field['fields'] ?? [] as $child => $definition) {
                $attributes['values.'.$key.'.*.'.$child] = __($definition['label']);
            }
        }
        $validator = Validator::make(
            ['values' => $input],
            ['values' => ['array:'.implode(',', array_keys($fields))]] + $this->rules($fields, 'values.', $theme),
            [],
            $attributes,
        );
        $validated = $validator->validate()['values'] ?? [];
        $values = [];
        foreach ($fields as $key => $field) {
            $values[$key] = $this->normalize($field, $validated[$key] ?? $this->empty($field));
        }

        return $values;
    }

    public function save(array $theme, array $values): void
    {
        $this->settings->set($this->key($theme), $values);
        unset($this->resolved[$theme['id']]);
    }

    public function choices(array $field, ?array $theme = null): array
    {
        return match ($field['type']) {
            'media' => MediaAsset::query()
                ->when(isset($field['mime']), fn ($query) => $query->whereIn('mime', $field['mime']))
                ->orderBy('original_name')
                ->pluck('original_name', 'id')
                ->all(),
            'menu' => app(Navigation::class)->locations($theme),
            default => $field['options'] ?? [],
        };
    }

    private function fields(array $theme): array
    {
        $fields = [];
        foreach ($this->sections($theme) as $section) {
            $fields += $section['fields'];
        }

        return $fields;
    }

    private function key(array $theme): string
    {
        return 'theme.'.$theme['id'].'.configuration';
    }

    private function rules(array $fields, string $prefix = '', ?array $theme = null): array
    {
        $rules = [];
        foreach ($fields as $key => $field) {
            $path = $prefix.$key;
            $type = $field['type'];
            $rules[$path] = ['bail', ($field['required'] ?? false) ? 'required' : 'nullable'];
            $rules[$path] = array_merge(
                $rules[$path],
                match ($type) {
                    'boolean' => ['boolean'],
                    'number' => [
                        'numeric',
                        function ($attribute, $value, $fail): void {
                            if (is_numeric($value) && !is_finite((float) $value)) {
                                $fail('validation.numeric')->translate();
                            }
                        },
                    ],
                    'multiselect', 'repeater' => ['array', 'max:'.($field['max'] ?? 25)],
                    'media' => [
                        'integer',
                        Rule::exists('media_assets', 'id')->when(
                            isset($field['mime']),
                            fn ($rule) => $rule->whereIn('mime', $field['mime']),
                        ),
                    ],
                    'menu' => ['string', Rule::in(array_keys(app(Navigation::class)->locations($theme)))],
                    'select' => ['string', Rule::in(array_keys($field['options']))],
                    'color' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
                    'url' => [
                        'string',
                        'max:'.($field['max'] ?? 2000),
                        fn ($attribute, $value, $fail) => NavigationTargets::validUrl($value) ?:
                        $fail(__('themes.invalid_url')),
                    ],
                    default => ['string', 'max:'.($field['max'] ?? ($type === 'text' ? 255 : 50000))],
                },
            );
            if (
                isset($field['min']) &&
                in_array($type, ['number', 'repeater', 'multiselect', 'text', 'textarea', 'richtext'], true)
            ) {
                $rules[$path][] = 'min:'.$field['min'];
            }
            if (isset($field['max']) && $type === 'number') {
                $rules[$path][] = 'max:'.$field['max'];
            }
            if ($type === 'multiselect') {
                $rules[$path.'.*'] = ['required', 'string', 'distinct', Rule::in(array_keys($field['options']))];
            }
            if ($type === 'repeater') {
                $rules[$path.'.*'] = ['required', 'array:'.implode(',', array_keys($field['fields']))];
                $rules += $this->rules($field['fields'], $path.'.*.', $theme);
            }
        }

        return $rules;
    }

    private function empty(array $field): array|false|null
    {
        return match ($field['type']) {
            'boolean' => false,
            'repeater', 'multiselect' => [],
            default => null,
        };
    }

    private function prepare(array $fields, array $input): array
    {
        foreach ($fields as $key => $field) {
            $empty = $this->empty($field);
            if ($empty !== null) {
                $input[$key] ??= $empty;
            }
            $value = $input[$key] ?? null;
            if ($field['type'] === 'multiselect' && $value === '') {
                $input[$key] = [];
            }
            if ($field['type'] === 'repeater' && is_array($value)) {
                $input[$key] = array_map(
                    fn ($row) => is_array($row) ? $this->prepare($field['fields'], $row) : $row,
                    $value,
                );
            }
        }

        return $input;
    }

    private function normalize(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'boolean' => (bool) $value,
            'number' => $value === null || $value === '' ? null : $value + 0,
            'media' => $value === null || $value === '' ? null : (int) $value,
            'richtext' => Html::clean($value),
            'multiselect' => array_values($value ?? []),
            'repeater' => array_values(
                array_map(function (array $row) use ($field): array {
                    $values = [];
                    foreach ($field['fields'] as $key => $child) {
                        $candidate = array_key_exists($key, $row) ? $row[$key] : ($child['default'] ?? $this->empty($child));
                        $values[$key] = $this->normalize($child, $candidate);
                    }

                    return $values;
                }, $value ?? []),
            ),
            default => $value,
        };
    }

    private static function validateField(mixed $key, mixed $field, bool $nested = false): void
    {
        if (
            !self::identifier($key) ||
            !is_array($field) ||
            !in_array($field['type'] ?? null, self::TYPES, true) ||
            !self::label($field['label'] ?? null) ||
            (isset($field['help']) && !self::label($field['help'])) ||
            (isset($field['required']) && !is_bool($field['required']))
        ) {
            self::invalid();
        }
        foreach (['min', 'max'] as $limit) {
            if (isset($field[$limit]) && (!is_numeric($field[$limit]) || !is_finite((float) $field[$limit]))) {
                self::invalid();
            }
            if (
                isset($field[$limit]) &&
                $field['type'] !== 'number' &&
                (!is_int($field[$limit]) || $field[$limit] < 0)
            ) {
                self::invalid();
            }
        }
        if (isset($field['min'], $field['max']) && $field['min'] > $field['max']) {
            self::invalid();
        }
        if (isset($field['mime'])) {
            if ($field['type'] !== 'media' || !is_array($field['mime']) || !$field['mime']) {
                self::invalid();
            }
            foreach ($field['mime'] as $mime) {
                if (!is_string($mime) || !preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~D', $mime)) {
                    self::invalid();
                }
            }
        }
        if (in_array($field['type'], ['select', 'multiselect'], true)) {
            if (!is_array($field['options'] ?? null) || !$field['options']) {
                self::invalid();
            }
            foreach ($field['options'] as $value => $label) {
                if (!self::identifier((string) $value) || !self::label($label)) {
                    self::invalid();
                }
            }
        }
        if ($field['type'] === 'repeater') {
            if ($nested || !is_array($field['fields'] ?? null) || !$field['fields']) {
                self::invalid();
            }
            foreach ($field['fields'] as $childKey => $child) {
                self::validateField($childKey, $child, true);
            }
        }
        if (array_key_exists('default', $field)) {
            if (!self::validDefault($field, $field['default'])) {
                self::invalid();
            }
        }
    }

    private static function validDefault(array $field, mixed $default): bool
    {
        $valid =
            $default === null ||
            match ($field['type']) {
                'boolean' => is_bool($default),
                'number' => (is_int($default) || is_float($default)) && is_finite((float) $default),
                'media' => is_int($default) && $default > 0,
                'multiselect' => is_array($default) &&
                    array_is_list($default) &&
                    count(array_filter($default, 'is_string')) === count($default) &&
                    !array_diff($default, array_keys($field['options'])),
                'repeater' => is_array($default) && array_is_list($default),
                'select' => is_string($default) && array_key_exists($default, $field['options']),
                'color' => is_string($default) && preg_match('/^#[0-9a-fA-F]{6}$/D', $default),
                'url' => is_string($default) && ($default === '' || NavigationTargets::validUrl($default)),
                default => is_string($default),
            };
        if (!$valid || $default === null) {
            return $valid;
        }
        $size = match (true) {
            is_array($default) => count($default),
            $field['type'] === 'number' => $default,
            is_string($default) => mb_strlen($default),
            default => null,
        };
        if (
            $size !== null &&
            ((isset($field['min']) && $size < $field['min']) || (isset($field['max']) && $size > $field['max']))
        ) {
            return false;
        }
        if ($field['type'] === 'repeater') {
            foreach ($default as $row) {
                if (!is_array($row) || array_diff(array_keys($row), array_keys($field['fields']))) {
                    return false;
                }
                if (array_any($row, fn ($value, $key) => !self::validDefault($field['fields'][$key], $value))) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $value);
    }

    private static function label(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 255;
    }

    private static function invalid(): never
    {
        throw new RuntimeException(__('system.extensions.invalid_manifest'));
    }
}
