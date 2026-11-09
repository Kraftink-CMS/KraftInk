<?php

namespace App\Kraft\Extensions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Settings;
use App\Models\Content\ContentEntry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class ThemeTemplates
{
    public function __construct(private Settings $settings)
    {
    }

    public static function validateManifest(array $manifest): void
    {
        if (!array_key_exists('templates', $manifest)) {
            return;
        }
        if (($manifest['type'] ?? null) !== 'theme' || !is_array($manifest['templates'])) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        foreach ($manifest['templates'] as $id => $template) {
            if (
                !self::identifier($id) ||
                !is_array($template) ||
                !self::label($template['label'] ?? null) ||
                !self::viewName($template['view'] ?? null, true) ||
                !is_array($template['choices'] ?? null) ||
                empty($template['choices']) ||
                (isset($template['routes']) && (!is_array($template['routes']) || !array_is_list($template['routes'])))
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            foreach ($template['routes'] ?? [] as $route) {
                if (!is_string($route) || !preg_match('/^[A-Za-z0-9_.-]{1,160}$/D', $route)) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
            }
            foreach ($template['choices'] as $choice => $definition) {
                if (
                    !self::identifier($choice) ||
                    !is_array($definition) ||
                    !self::label($definition['label'] ?? null) ||
                    !self::viewName($definition['view'] ?? null)
                ) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
            }
            if (
                isset($template['default']) &&
                (!is_string($template['default']) || !array_key_exists($template['default'], $template['choices']))
            ) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
        }
    }

    public function fields(array $theme): array
    {
        $fields = [];
        foreach ($theme['templates'] ?? [] as $id => $template) {
            $fields[$id] = [
                'type' => 'select',
                'label' => $template['label'],
                'options' => ['' => 'themes.native_template'],
                'default' => $template['default'] ?? '',
            ];
            foreach ($template['choices'] as $choice => $definition) {
                $fields[$id]['options'][$choice] = $definition['label'];
            }
        }

        return $fields;
    }

    public function selections(array $theme): array
    {
        $saved = $this->saved($theme, 'templates');
        $values = [];
        foreach ($theme['templates'] ?? [] as $id => $template) {
            $selected = $saved[$id] ?? ($template['default'] ?? '');
            $values[$id] = is_string($selected) && isset($template['choices'][$selected]) ? $selected : '';
        }

        return $values;
    }

    public function validate(array $theme, array $input): array
    {
        $fields = $this->fields($theme);
        $rules = ['templates' => $fields ? ['array:'.implode(',', array_keys($fields))] : ['array', 'size:0']];
        foreach ($fields as $id => $field) {
            $rules['templates.'.$id] = ['sometimes', 'nullable', 'string', Rule::in(array_keys($field['options']))];
        }
        $validator = Validator::make(['templates' => $input], $rules);
        $validator->validate();
        $values = $this->selections($theme);
        foreach ($input as $id => $choice) {
            $values[$id] = $choice ?? '';
        }

        return $values;
    }

    public function save(array $theme, array $values): void
    {
        $this->settings->set('theme.'.$theme['id'].'.templates', $values);
    }

    public function contentField(array $theme): ?array
    {
        $choices = $this->contentChoices($theme);
        if (!$choices) {
            return null;
        }

        return [
            'type' => 'repeater',
            'label' => 'themes.content_assignments',
            'help' => 'themes.content_templates_help',
            'fields' => [
                'entry' => [
                    'type' => 'select',
                    'label' => 'themes.content_entry',
                    'required' => true,
                    'translate_options' => false,
                    'options' => ContentEntry::query()
                        ->orderBy('title')
                        ->get(['id', 'title', 'locale'])
                        ->mapWithKeys(
                            fn (ContentEntry $entry) => [$entry->id => $entry->title.' ('.$entry->locale.')'],
                        )
                        ->all(),
                ],
                'template' => [
                    'type' => 'select',
                    'label' => 'themes.template',
                    'required' => true,
                    'options' => $choices,
                ],
            ],
        ];
    }

    public function contentSelections(array $theme): array
    {
        $choices = $this->contentChoices($theme);
        if (!$choices) {
            return [];
        }
        $saved = $this->saved($theme, 'content_templates');
        $entries = ContentEntry::query()->whereIn('id', array_keys($saved))->pluck('id')->all();
        $rows = [];
        foreach ($entries as $id) {
            if (is_string($saved[$id]) && isset($choices[$saved[$id]])) {
                $rows[] = ['entry' => $id, 'template' => $saved[$id]];
            }
        }

        return $rows;
    }

    public function validateContent(array $theme, array $input): ?array
    {
        $choices = $this->contentChoices($theme);
        if (!$choices) {
            abort_if($input !== [], 403);

            return null;
        }
        $validator = Validator::make(
            ['content_templates' => $input],
            [
                'content_templates' => ['array'],
                'content_templates.*' => ['array:entry,template'],
                'content_templates.*.entry' => [
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('content_entries', 'id')->whereNull('deleted_at'),
                ],
                'content_templates.*.template' => [
                    'required',
                    'string',
                    Rule::in(array_keys($choices)),
                ],
            ],
        );
        $validated = $validator->validate();

        return array_column($validated['content_templates'], 'template', 'entry');
    }

    public function saveContent(array $theme, array $values): void
    {
        if (!$this->canManageContent()) {
            throw new HttpException(403);
        }
        $this->settings->set('theme.'.$theme['id'].'.content_templates', $values);
    }

    public function apply(View $view, array $theme): void
    {
        if (empty($theme['templates'])) {
            return;
        }
        $name = $view->name();
        $selections = null;
        $replacement = null;
        foreach ($theme['templates'] as $id => $template) {
            if ($template['view'] !== $name || !$this->matchesRoute($template)) {
                continue;
            }
            $selections ??= $this->selections($theme);
            $choice = $selections[$id];
            if (isset($template['choices'][$choice])) {
                $replacement = $template['choices'][$choice];
            }
        }
        $entry = $view->getData()['entry'] ?? null;
        if ($name === 'default.content.show' && $entry instanceof ContentEntry) {
            $saved = $this->saved($theme, 'content_templates');
            $token = $saved[$entry->id] ?? null;
            if (is_string($token) && str_contains($token, ':')) {
                [$surface, $choice] = explode(':', $token, 2);
                $template = $theme['templates'][$surface] ?? null;
                if ($template && $template['view'] === $name && $this->matchesRoute($template)) {
                    $replacement = $template['choices'][$choice] ?? $replacement;
                }
            }
        }
        if ($replacement && ($path = $this->path($theme, $replacement['view']))) {
            $view->setPath($path);
        }
    }

    private function contentChoices(array $theme): array
    {
        if (!$this->canManageContent()) {
            return [];
        }
        $choices = [];
        foreach ($theme['templates'] ?? [] as $id => $template) {
            if ($template['view'] !== 'default.content.show') {
                continue;
            }
            foreach ($template['choices'] as $choice => $definition) {
                $choices[$id.':'.$choice] = $definition['label'];
            }
        }

        return $choices;
    }

    private function matchesRoute(array $template): bool
    {
        return empty($template['routes']) || in_array(request()->route()?->getName(), $template['routes'], true);
    }

    private function path(array $theme, string $view): ?string
    {
        $root = realpath(ExtensionManager::viewsPath($theme));
        if (!$root || !self::viewName($view)) {
            return null;
        }
        $file = realpath($root.'/'.str_replace('.', '/', $view).'.blade.php');
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';

        return $file && is_file($file) && str_starts_with(str_replace('\\', '/', $file), $prefix) ? $file : null;
    }

    private function canManageContent(): bool
    {
        return request()->user()?->hasPermission('content.manage') ?? false;
    }

    private function saved(array $theme, string $key): array
    {
        $draft = app(ThemePreview::class)->draft($theme);
        $values = $draft[$key] ?? $this->settings->get('theme.'.$theme['id'].'.'.$key, []);

        return is_array($values) ? $values : [];
    }

    private static function identifier(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $id) === 1;
    }

    private static function label(mixed $label): bool
    {
        return is_string($label) && trim($label) !== '' && strlen($label) <= 255;
    }

    private static function viewName(mixed $view, bool $namespaced = false): bool
    {
        $pattern = $namespaced
            ? '/^(?:[A-Za-z0-9_-]+::)?[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/D'
            : '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/D';

        return is_string($view) && strlen($view) <= 255 && preg_match($pattern, $view) === 1;
    }
}
