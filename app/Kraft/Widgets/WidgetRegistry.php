<?php

namespace App\Kraft\Widgets;

use App\Kraft\Extensions\ExtensionManager;
use InvalidArgumentException;

class WidgetRegistry
{
    /**
     * @var array<string, array{
     *     owner: string,
     *     id: string,
     *     label: string,
     *     render: callable|string,
     *     fields: array<string, array>,
     *     permission: string|null,
     *     zones: array<array-key, string>,
     *     defaults: array
     * }>
     */
    private array $widgets = [];

    /** @var array<string, string> */
    private array $zones = ['home' => 'widgets.zones.home'];

    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    public function zone(string $id, string $label): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $id) || $label === '') {
            throw new InvalidArgumentException('Invalid widget zone.');
        }
        $this->zones[$id] = $label;
    }

    /** @return array<string, string> */
    public function zones(): array
    {
        return $this->zones;
    }

    public function register(
        string $owner,
        string $id,
        string $label,
        mixed $render,
        array $fields = [],
        ?string $permission = null,
        array $zones = ['home'],
        array $defaults = [],
    ): void {
        if (
            !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $owner) ||
            !preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $id) ||
            $label === '' ||
            (!is_callable($render) && !is_string($render)) ||
            !$zones ||
            array_diff($zones, array_keys($this->zones)) ||
            ($permission !== null && !preg_match('/^[a-z][a-z0-9_.-]{1,119}$/D', $permission)) ||
            count($fields) > 20
        ) {
            throw new InvalidArgumentException('Invalid widget definition.');
        }
        foreach ($fields as $name => $field) {
            if (
                !preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $name) ||
                !is_array($field) ||
                !in_array($field['type'] ?? null, ['integer', 'text', 'boolean', 'choice'], true) ||
                !is_string($field['label'] ?? null) ||
                ($field['type'] === 'choice' && (empty($field['options']) || !is_array($field['options'])))
            ) {
                throw new InvalidArgumentException('Invalid widget configuration field.');
            }
        }
        $this->widgets[$owner.':'.$id] = compact(
            'owner',
            'id',
            'label',
            'render',
            'fields',
            'permission',
            'zones',
            'defaults',
        );
    }

    /**
     * @return array<string, array{
     *     owner: string,
     *     id: string,
     *     label: string,
     *     render: callable|string,
     *     fields: array<string, array>,
     *     permission: string|null,
     *     zones: array<array-key, string>,
     *     defaults: array
     * }>
     */
    public function all(): array
    {
        $extensions = $this->extensions->runtimeContributors();

        return array_filter($this->widgets, function (array $widget) use ($extensions): bool {
            $extension = $extensions[$widget['owner']] ?? null;

            return $extension &&
                (($extension['type'] ?? null) !== 'theme' || $extension['id'] === $this->extensions->selectedTheme());
        });
    }

    /**
     * @return array{
     *     owner: string,
     *     id: string,
     *     label: string,
     *     render: callable|string,
     *     fields: array<string, array>,
     *     permission: string|null,
     *     zones: array<array-key, string>,
     *     defaults: array
     * }|null
     */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }
}
