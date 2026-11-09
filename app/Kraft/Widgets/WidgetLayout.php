<?php

namespace App\Kraft\Widgets;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Models\Setting;
use App\Kraft\Auth\PermissionAccess;
use App\Kraft\Extensions\ContributionRenderer;
use App\Kraft\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Closure;
use Throwable;

class WidgetLayout
{
    private array $rendering = [];

    private array $errors = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly WidgetRegistry $widgets,
        private readonly ContributionRenderer $renderer,
    ) {
    }

    public function all(): array
    {
        $instances = $this->settings->get('widgets.instances', []);

        return is_array($instances) ? array_values(array_filter($instances, 'is_array')) : [];
    }

    public function store(?string $id, array $data): string
    {
        $key = $data['widget'] ?? null;
        $widget = is_string($key) ? $this->widgets->get($key) : null;
        if (!$widget) {
            throw ValidationException::withMessages(['widget' => __('widgets.unavailable')]);
        }
        $validator = Validator::make($data, [
            'widget' => ['required', 'string'],
            'zone' => ['required', Rule::in($widget['zones'])],
            'enabled' => ['required', 'boolean'],
            'width' => ['required', Rule::in(['full', 'half', 'third'])],
            'locales' => ['nullable', 'array', 'max:20'],
            'locales.*' => ['string', 'distinct', Rule::in(array_keys(config('kraft.locales', [])))],
            'config' => ['nullable', 'array'],
        ]);
        $data = $validator->validate();
        $data['config'] = $this->configuration($widget, $data['config'] ?? []);
        $data['locales'] = array_values($data['locales'] ?? []);
        $data['enabled'] = (bool) $data['enabled'];
        $savedId = $id ?? (string) Str::uuid();
        $this->mutate(function (array $instances) use ($id, $savedId, $data): array {
            $index = $id === null ? false : array_search($id, array_column($instances, 'id'), true);
            if ($id !== null && $index === false) {
                throw new NotFoundHttpException();
            }
            if ($id === null && count($instances) >= 100) {
                throw ValidationException::withMessages(['widget' => __('widgets.limit')]);
            }
            if ($id !== null && $instances[$index]['widget'] !== $data['widget']) {
                throw ValidationException::withMessages(['widget' => __('widgets.invalid_widget')]);
            }
            $instance = ['id' => $savedId] + $data;
            if ($index === false) {
                $instances[] = $instance;
            } else {
                $instances[$index] = $instance;
            }

            return $instances;
        });

        return $savedId;
    }

    public function destroy(string $id): void
    {
        $this->mutate(function (array $instances) use ($id): array {
            if (!in_array($id, array_column($instances, 'id'), true)) {
                throw new NotFoundHttpException();
            }

            return array_values(array_filter($instances, fn (array $instance) => $instance['id'] !== $id));
        });
    }

    public function move(string $id, string $direction): void
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => __('validation.in', ['attribute' => 'direction'])]);
        }
        $this->mutate(function (array $instances) use ($id, $direction): array {
            $index = array_search($id, array_column($instances, 'id'), true);
            if ($index === false) {
                throw new NotFoundHttpException();
            }
            $step = $direction === 'up' ? -1 : 1;
            for ($target = $index + $step; isset($instances[$target]); $target += $step) {
                if ($instances[$target]['zone'] === $instances[$index]['zone']) {
                    [$instances[$index], $instances[$target]] = [$instances[$target], $instances[$index]];
                    break;
                }
            }

            return $instances;
        });
    }

    public function render(string $zone, array $context = []): HtmlString
    {
        if (isset($this->rendering[$zone]) || !isset($this->widgets->zones()[$zone])) {
            return new HtmlString('');
        }
        $this->rendering[$zone] = true;
        try {
            $definitions = $this->widgets->all();
            $html = '';
            foreach ($this->all() as $instance) {
                $widget = $definitions[$instance['widget'] ?? ''] ?? null;
                if (
                    !$widget ||
                    !($instance['enabled'] ?? false) ||
                    ($instance['zone'] ?? null) !== $zone ||
                    !in_array($zone, $widget['zones'], true) ||
                    (!empty($instance['locales']) && !in_array(app()->getLocale(), $instance['locales'], true)) ||
                    ($widget['permission'] &&
                        !app(PermissionAccess::class)->allows(auth()->user(), $widget['permission']))
                ) {
                    continue;
                }
                $output = $this->contribution($widget, $instance, $context);
                if ($output === null || trim($output) === '') {
                    continue;
                }
                $column = match ($instance['width'] ?? 'half') {
                    'full' => 'col-12',
                    'third' => 'col-12 col-md-6 col-xl-4',
                    default => 'col-12 col-lg-6',
                };
                $html .= '<div class="'.$column.'">'.$output.'</div>';
            }

            return new HtmlString($html === '' ? '' : '<div class="row g-4">'.$html.'</div>');
        } finally {
            unset($this->rendering[$zone]);
        }
    }

    public function diagnostics(): array
    {
        return $this->errors;
    }

    public function configuration(array $widget, array $values): array
    {
        $unknown = array_diff(array_keys($values), array_keys($widget['fields']));
        if ($unknown) {
            throw ValidationException::withMessages(['config' => __('widgets.invalid_configuration')]);
        }
        $rules = [];
        $configuration = [];
        foreach ($widget['fields'] as $name => $field) {
            $default =
                $widget['defaults'][$name] ??
                ($field['default'] ??
                    match ($field['type']) {
                        'boolean' => false,
                        'integer' => $field['min'] ?? 1,
                        'choice' => array_key_first($field['options']),
                        default => '',
                    });
            $configuration[$name] = $values[$name] ?? $default;
            $rules[$name] = match ($field['type']) {
                'integer' => ['required', 'integer', 'min:'.($field['min'] ?? 1), 'max:'.($field['max'] ?? 100)],
                'boolean' => ['required', 'boolean'],
                'choice' => ['required', Rule::in(array_keys($field['options']))],
                default => ['nullable', 'string', 'max:'.min(1000, $field['max'] ?? 255)],
            };
        }
        $validator = Validator::make($configuration, $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->messages())
                    ->mapWithKeys(fn ($messages, $name) => ['config.'.$name => $messages])
                    ->all(),
            );
        }
        foreach ($widget['fields'] as $name => $field) {
            $configuration[$name] = match ($field['type']) {
                'integer' => (int) $configuration[$name],
                'boolean' => (bool) $configuration[$name],
                default => $configuration[$name] ?? '',
            };
        }

        return $configuration;
    }

    private function contribution(array $widget, array $instance, array $context): ?string
    {
        try {
            return $this->renderer->render(function () use ($widget, $instance, $context) {
                $config = $this->configuration($widget, $instance['config'] ?? []);
                $context = array_replace($context, [
                    'user' => auth()->user(),
                    'locale' => app()->getLocale(),
                    'instance' => $instance,
                ]);

                return is_string($widget['render']) && !is_callable($widget['render'])
                    ? view($widget['render'], compact('context', 'config'))
                    : app()->call($widget['render'], compact('context', 'config'));
            });
        } catch (Throwable $exception) {
            $this->errors[$instance['id']] = 'render_failed';
            try {
                Log::warning('Widget unavailable', [
                    'widget' => $instance['widget'],
                    'instance' => $instance['id'],
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
            }

            return null;
        }
    }

    private function mutate(Closure $change): void
    {
        DB::transaction(function () use ($change): void {
            Setting::query()->firstOrCreate(['key' => 'widgets.instances'], ['value' => []]);
            $stored = Setting::query()->where('key', 'widgets.instances')->lockForUpdate()->value('value');
            $instances = $stored;
            $this->settings->set('widgets.instances', array_values($change($instances)));
        }, 3);
    }
}
